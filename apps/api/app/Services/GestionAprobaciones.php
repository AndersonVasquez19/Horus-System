<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones de escritura del módulo de Aprobaciones internas.
 *
 * Toda operación sobre una solicitud existente es transaccional, bloquea la fila (`lockForUpdate`) y relee el
 * estado bajo bloqueo antes de decidir; la auditoría es la última escritura, de modo que un fallo de auditoría
 * o de notificación (escrita por los eventos del modelo en la misma transacción) revierte la operación completa.
 *
 * Autoridad: {@see AprobacionInterna::TIPOS_AUTORIDAD}. El solicitante nunca resuelve su propia solicitud.
 * SQLite ignora `lockForUpdate()`: la serialización real solo se mide en tests/PostgresIntegrity.
 */
class GestionAprobaciones
{
    public const CONFLICTO = 409;

    /**
     * @param  array{titulo: string, tipo: string, descripcion?: string|null, comentario?: string|null, referencia?: string|null}  $datos
     */
    public function registrar(User $actor, array $datos): AprobacionInterna
    {
        return DB::transaction(function () use ($actor, $datos): AprobacionInterna {
            // Identidad y estado fijados en el servidor: no se aceptan solicitante, aprobador ni estado del cliente.
            $aprobacion = AprobacionInterna::create([
                'titulo' => $datos['titulo'],
                'tipo' => $datos['tipo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'comentario' => $datos['comentario'] ?? null,
                'referencia' => $datos['referencia'] ?? null,
                'solicitante_id' => $actor->id,
                'aprobador_id' => null,
                'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            ]);

            $this->auditar('aprobacion_creada', $aprobacion, $actor, null, AprobacionInterna::ESTADO_PENDIENTE, 'crear');

            if ($aprobacion->aprobador_id === null) {
                foreach ($aprobacion->responsablesIds() as $userId) {
                    if ($userId !== (int) $actor->id) {
                        NotificacionInterna::crearPersonal($userId, [
                            'titulo' => 'Nueva solicitud de aprobación',
                            'mensaje' => 'Hay una solicitud pendiente para revisar: '.$aprobacion->titulo,
                            'tipo' => 'warning',
                            'leida' => false,
                            'vinculo' => '/admin/aprobaciones',
                        ]);
                    }
                }
            }

            return $aprobacion;
        });
    }

    /**
     * Edición de datos descriptivos de una solicitud pendiente. Tipo, estado, solicitante y aprobador no se editan.
     *
     * @param  array{titulo: string, descripcion?: string|null, comentario?: string|null, referencia?: string|null}  $datos
     */
    public function actualizar(User $actor, int $id, array $datos): AprobacionInterna
    {
        return DB::transaction(function () use ($actor, $id, $datos): AprobacionInterna {
            $solicitud = $this->bloquear($id);
            $this->exigirPendiente($solicitud, 'No se puede modificar una solicitud que ya ha sido resuelta.');
            $this->exigirPropietarioOGerencia($solicitud, $actor, 'modificar');

            $solicitud->update([
                'titulo' => $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'comentario' => $datos['comentario'] ?? $solicitud->comentario,
                'referencia' => $datos['referencia'] ?? $solicitud->referencia,
            ]);

            $this->auditar('aprobacion_actualizada', $solicitud, $actor, $solicitud->estado, $solicitud->estado, 'actualizar');

            return $solicitud;
        });
    }

    public function eliminar(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $solicitud = $this->bloquear($id);
            $this->exigirPendiente($solicitud, 'No se puede eliminar una solicitud que ya ha sido resuelta.');
            $this->exigirPropietarioOGerencia($solicitud, $actor, 'eliminar');

            $this->auditar('aprobacion_eliminada', $solicitud, $actor, $solicitud->estado, null, 'eliminar');
            $solicitud->delete();
        });
    }

    public function aprobar(User $actor, int $id, ?string $comentario): AprobacionInterna
    {
        return DB::transaction(function () use ($actor, $id, $comentario): AprobacionInterna {
            $solicitud = $this->bloquear($id);
            $this->exigirDecisionValida($solicitud, $actor, 'aprobar');

            $solicitud->update([
                'estado' => AprobacionInterna::ESTADO_APROBADO,
                'aprobador_id' => $actor->id,
                'comentario' => ($comentario !== null && $comentario !== '') ? $comentario : $solicitud->comentario,
            ]);

            $this->auditar('aprobacion_aprobada', $solicitud, $actor, AprobacionInterna::ESTADO_PENDIENTE,
                AprobacionInterna::ESTADO_APROBADO, 'aprobar', ['comentario' => $comentario]);

            return $solicitud;
        });
    }

    public function rechazar(User $actor, int $id, string $motivo): AprobacionInterna
    {
        $this->exigirMotivo($motivo);

        return DB::transaction(function () use ($actor, $id, $motivo): AprobacionInterna {
            $solicitud = $this->bloquear($id);
            $this->exigirDecisionValida($solicitud, $actor, 'rechazar');

            $solicitud->update([
                'estado' => AprobacionInterna::ESTADO_RECHAZADO,
                'aprobador_id' => $actor->id,
                'comentario' => $motivo,
            ]);

            $this->auditar('aprobacion_rechazada', $solicitud, $actor, AprobacionInterna::ESTADO_PENDIENTE,
                AprobacionInterna::ESTADO_RECHAZADO, 'rechazar', ['motivo' => $motivo]);

            return $solicitud;
        });
    }

    public function reabrir(User $actor, int $id, string $motivo): AprobacionInterna
    {
        $this->exigirMotivo($motivo);

        return DB::transaction(function () use ($actor, $id, $motivo): AprobacionInterna {
            $solicitud = $this->bloquear($id);

            abort_if($solicitud->estado === AprobacionInterna::ESTADO_PENDIENTE, self::CONFLICTO,
                'La solicitud ya se encuentra pendiente.');
            abort_unless($solicitud->puedeReabrir($actor), 403, 'No tienes autoridad para reabrir esta solicitud.');

            $estadoAnterior = $solicitud->estado;
            $aprobadorAnterior = $solicitud->aprobador_id;
            $comentarioAnterior = $solicitud->comentario;

            // La decisión anterior (estado, decisor y motivo) queda en la auditoría; la fila vuelve a quedar sin decisor.
            $solicitud->update([
                'estado' => AprobacionInterna::ESTADO_PENDIENTE,
                'aprobador_id' => null,
                'comentario' => 'Reabierta por '.$actor->name.': '.$motivo,
            ]);

            $this->auditar('aprobacion_reabierta', $solicitud, $actor, $estadoAnterior, AprobacionInterna::ESTADO_PENDIENTE,
                'reabrir', [
                    'motivo' => $motivo,
                    'aprobador_anterior_id' => $aprobadorAnterior,
                    'comentario_anterior' => $comentarioAnterior,
                ]);

            return $solicitud;
        });
    }

    private function bloquear(int $id): AprobacionInterna
    {
        return AprobacionInterna::query()->lockForUpdate()->findOrFail($id);
    }

    /** Orden de comprobación: estado releído bajo bloqueo, autoaprobación y autoridad por tipo. */
    private function exigirDecisionValida(AprobacionInterna $solicitud, User $actor, string $accion): void
    {
        $this->exigirPendiente($solicitud, 'La solicitud ya ha sido resuelta y no puede decidirse nuevamente.');

        abort_if($solicitud->esSolicitante($actor), 403, "No puedes {$accion} tu propia solicitud.");
        abort_unless($solicitud->puedeResolver($actor), 403,
            "No tienes autoridad para {$accion} solicitudes de tipo {$solicitud->tipo}.");
    }

    private function exigirPendiente(AprobacionInterna $solicitud, string $mensaje): void
    {
        abort_if($solicitud->estado !== AprobacionInterna::ESTADO_PENDIENTE, self::CONFLICTO, $mensaje);
    }

    private function exigirPropietarioOGerencia(AprobacionInterna $solicitud, User $actor, string $accion): void
    {
        abort_unless($solicitud->esSolicitante($actor) || $actor->hasRole('admin'), 403,
            "Solo el solicitante o Gerencia pueden {$accion} esta solicitud.");
    }

    /** Defensa en profundidad: el FormRequest/validate del controlador ya lo exige. */
    private function exigirMotivo(string $motivo): void
    {
        if (mb_strlen(trim($motivo)) < 3) {
            throw ValidationException::withMessages(['motivo' => 'El motivo es obligatorio (mínimo 3 caracteres).']);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditar(
        string $accion,
        AprobacionInterna $solicitud,
        User $actor,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        string $operacion,
        array $extra = [],
    ): void {
        AuditLog::record($accion, AprobacionInterna::class, $solicitud->id, [
            'solicitud_id' => $solicitud->id,
            'titulo' => $solicitud->titulo,
            'tipo' => $solicitud->tipo,
            'rol_autorizado' => $solicitud->rolAutorizado(),
            'solicitante_id' => $solicitud->solicitante_id,
            'responsable_id' => $actor->id,
            'responsable_nombre' => $actor->name,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'accion' => $operacion,
            'referencia' => $solicitud->referencia,
        ] + $extra, $actor->id);
    }
}
