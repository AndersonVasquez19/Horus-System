<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\OportunidadHistorial;
use App\Models\Prospecto;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pipeline de oportunidades del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.3).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (428/412), rol y permiso releídos con
 * datos vigentes, etapas cerradas (se avanza de a una, se pierde con motivo, una cerrada solo se reabre) e historial de
 * cada cambio de etapa. La auditoría guarda revisión y etapas, nunca el título, el motivo ni datos del cliente.
 */
class GestionOportunidades
{
    /**
     * @param  array<string, mixed>  $datos  `cliente` (UUID), `titulo` y opcionales `monto_estimado`, `probabilidad`,
     *                                       `fecha_cierre_estimada`, `servicio` y `cotizacion` (UUID)
     */
    public function crear(Request $request, array $datos, ?Prospecto $prospecto = null): Oportunidad
    {
        return DB::transaction(function () use ($request, $datos, $prospecto): Oportunidad {
            $autor = $this->autorizar($request, 'pipeline.gestionar');
            abort_unless($autor->can('clientes.ver'), 403);

            $cliente = Cliente::query()->where('identificador', strtolower((string) ($datos['cliente'] ?? '')))->first();
            if ($cliente === null) {
                throw ValidationException::withMessages(['cliente' => 'Selecciona un cliente existente.']);
            }
            if ($cliente->estado !== 'activo') {
                throw ValidationException::withMessages(['cliente' => 'El cliente está inactivo: reactívalo antes de abrir una oportunidad.']);
            }

            $oportunidad = new Oportunidad(array_intersect_key($datos, array_flip(['titulo', 'monto_estimado', 'fecha_cierre_estimada'])));
            $oportunidad->identificador = (string) Str::uuid();
            $oportunidad->cliente_id = $cliente->id;
            $oportunidad->prospecto_id = $prospecto?->id;
            $oportunidad->servicio_id = $this->servicio($datos['servicio'] ?? null);
            $oportunidad->cotizacion_id = $this->cotizacion($autor, $datos['cotizacion'] ?? null);
            $oportunidad->etapa = 'nueva';
            $oportunidad->monto_estimado ??= 0;
            $oportunidad->probabilidad = (int) ($datos['probabilidad'] ?? Oportunidad::PROBABILIDAD['nueva']);
            $oportunidad->responsable_id = $autor->id;
            $oportunidad->revision = 1;
            $oportunidad->save();
            $this->registrarHistorial($oportunidad, null, 'nueva', $autor);
            AuditLog::record('oportunidad_creada', Oportunidad::class, $oportunidad->id, ['revision' => 1], $autor->id);

            return $oportunidad;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): Oportunidad
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Oportunidad {
            $oportunidad = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'pipeline.gestionar');
            $this->comprobarRevision($request, $oportunidad);
            abort_unless($oportunidad->estaAbierta(), 409, 'Una oportunidad cerrada no se edita: reábrela primero.');

            $campos = array_intersect_key($datos, array_flip(['titulo', 'monto_estimado', 'probabilidad', 'fecha_cierre_estimada']));
            // Monto y probabilidad no admiten nulo: si no llegan, se conservan.
            foreach (['monto_estimado', 'probabilidad'] as $obligatorio) {
                if (($campos[$obligatorio] ?? null) === null) {
                    unset($campos[$obligatorio]);
                }
            }
            $oportunidad->fill($campos);
            if (! $oportunidad->isDirty()) {
                return $oportunidad;
            }

            $oportunidad->revision++;
            $oportunidad->save();
            AuditLog::record('oportunidad_actualizada', Oportunidad::class, $oportunidad->id, ['revision' => $oportunidad->revision], $autor->id);

            return $oportunidad;
        });
    }

    /** Avanza, pierde o reabre. Cada cambio de etapa deja una fila en el historial. */
    public function cambiarEtapa(Request $request, string $identificador, string $etapa, ?string $motivo): Oportunidad
    {
        if (! in_array($etapa, Oportunidad::ETAPAS, true)) {
            throw ValidationException::withMessages(['etapa' => 'Etapa no permitida.']);
        }

        return DB::transaction(function () use ($request, $identificador, $etapa, $motivo): Oportunidad {
            $oportunidad = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'pipeline.gestionar');
            $this->comprobarRevision($request, $oportunidad);

            if ($oportunidad->etapa === $etapa) {
                return $oportunidad;
            }
            abort_unless(in_array($etapa, Oportunidad::TRANSICIONES[$oportunidad->etapa] ?? [], true), 409, 'Transición no permitida desde la etapa actual.');
            if ($etapa === 'perdida' && blank($motivo)) {
                throw ValidationException::withMessages(['motivo_perdida' => 'Indica el motivo de la pérdida.']);
            }

            $anterior = $oportunidad->etapa;
            $oportunidad->etapa = $etapa;
            $oportunidad->probabilidad = Oportunidad::PROBABILIDAD[$etapa];
            $oportunidad->motivo_perdida = $etapa === 'perdida' ? $motivo : null;
            $oportunidad->cerrada_at = in_array($etapa, Oportunidad::CERRADAS, true) ? now() : null;
            $oportunidad->revision++;
            $oportunidad->save();
            $this->registrarHistorial($oportunidad, $anterior, $etapa, $autor);

            $accion = match (true) {
                in_array($anterior, Oportunidad::CERRADAS, true) => 'oportunidad_reabierta',
                $etapa === 'ganada' => 'oportunidad_ganada',
                $etapa === 'perdida' => 'oportunidad_perdida',
                default => 'oportunidad_etapa_cambiada',
            };
            AuditLog::record($accion, Oportunidad::class, $oportunidad->id,
                ['revision' => $oportunidad->revision, 'anterior' => $anterior, 'etapa' => $etapa], $autor->id);

            return $oportunidad;
        });
    }

    /** Cambia el responsable. Solo puede serlo un administrador que pueda ver el pipeline. */
    public function asignarResponsable(Request $request, string $identificador, ?int $responsableId): Oportunidad
    {
        return DB::transaction(function () use ($request, $identificador, $responsableId): Oportunidad {
            $oportunidad = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'pipeline.gestionar');
            $this->comprobarRevision($request, $oportunidad);

            if ($responsableId !== null) {
                $responsable = User::query()->find($responsableId);
                if (! $responsable instanceof User || ! $responsable->hasRole('admin') || ! $responsable->can('pipeline.ver')) {
                    throw ValidationException::withMessages(['responsable_id' => 'El responsable no es válido.']);
                }
            }
            if ($oportunidad->responsable_id === $responsableId) {
                return $oportunidad;
            }
            $oportunidad->responsable_id = $responsableId;
            $oportunidad->revision++;
            $oportunidad->save();
            AuditLog::record('oportunidad_responsable_actualizado', Oportunidad::class, $oportunidad->id, ['revision' => $oportunidad->revision], $autor->id);

            return $oportunidad;
        });
    }

    private function registrarHistorial(Oportunidad $oportunidad, ?string $anterior, string $nueva, User $autor): void
    {
        $fila = new OportunidadHistorial;
        $fila->oportunidad_id = $oportunidad->id;
        $fila->etapa_anterior = $anterior;
        $fila->etapa_nueva = $nueva;
        $fila->user_id = $autor->id;
        $fila->save();
    }

    private function servicio(mixed $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $servicio = ServicioTecnologico::query()->where('identificador_publico', (string) $uuid)->where('estado', '!=', 'archivado')->first();
        if ($servicio === null) {
            throw ValidationException::withMessages(['servicio' => 'Selecciona un servicio existente.']);
        }

        return $servicio->id;
    }

    private function cotizacion(User $autor, mixed $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        abort_unless($autor->can('cotizaciones.ver'), 403);
        $solicitud = SolicitudCotizacion::query()->where('identificador', strtolower((string) $uuid))->first();
        if ($solicitud === null) {
            throw ValidationException::withMessages(['cotizacion' => 'Selecciona una solicitud de cotización existente.']);
        }

        return $solicitud->id;
    }

    private function bloquear(string $identificador): Oportunidad
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Oportunidad::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, Oportunidad $oportunidad): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle de la oportunidad.');
        if (! preg_match('/\A"oportunidad-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($oportunidad->etag(), $revision), 412, 'La oportunidad cambió. Relee y revisa antes de continuar.');
    }
}
