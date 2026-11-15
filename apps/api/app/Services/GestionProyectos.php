<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\Proyecto;
use App\Models\ProyectoHito;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proyectos del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.4).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (428/412), rol y permiso releídos con
 * datos vigentes, estados cerrados, código `PRY-AAAA-NNNN` bajo bloqueo y un solo proyecto por oportunidad ganada
 * (idempotente). Los hitos no tienen revisión propia: cada cambio avanza la del proyecto. La auditoría guarda
 * revisión, estados e indicadores, nunca nombres, descripciones ni datos del cliente.
 */
class GestionProyectos
{
    /**
     * Crea un proyecto de un cliente (`cliente`, UUID) o de una oportunidad ganada (`oportunidad`, UUID), que aporta el
     * cliente, el nombre y el monto si no se indican. Una oportunidad da un solo proyecto: repetir devuelve el mismo.
     *
     * @param  array<string, mixed>  $datos
     * @return array{proyecto: Proyecto, repetido: bool}
     */
    public function crear(Request $request, array $datos): array
    {
        try {
            return DB::transaction(function () use ($request, $datos): array {
                $autor = $this->autorizar($request, 'proyectos.crear');
                $proyecto = new Proyecto(array_intersect_key($datos, array_flip(['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto'])));

                if (filled($datos['oportunidad'] ?? null)) {
                    abort_unless($autor->can('pipeline.ver'), 403);
                    $oportunidad = Oportunidad::query()->lockForUpdate()->where('identificador', strtolower((string) $datos['oportunidad']))->first();
                    if ($oportunidad === null) {
                        throw ValidationException::withMessages(['oportunidad' => 'Selecciona una oportunidad existente.']);
                    }
                    $previo = Proyecto::query()->withTrashed()->where('oportunidad_id', $oportunidad->id)->first();
                    if ($previo !== null) {
                        return ['proyecto' => $previo, 'repetido' => true];
                    }
                    abort_unless($oportunidad->etapa === 'ganada', 409, 'Solo una oportunidad ganada da un proyecto.');
                    $proyecto->oportunidad_id = $oportunidad->id;
                    $proyecto->cliente_id = $oportunidad->cliente_id;
                    $proyecto->nombre ??= $oportunidad->titulo;
                    $proyecto->presupuesto ??= $oportunidad->monto_estimado;
                } else {
                    abort_unless($autor->can('clientes.ver'), 403);
                    $cliente = Cliente::query()->where('identificador', strtolower((string) ($datos['cliente'] ?? '')))->first();
                    if ($cliente === null) {
                        throw ValidationException::withMessages(['cliente' => 'Selecciona un cliente existente.']);
                    }
                    $proyecto->cliente_id = $cliente->id;
                }

                $cliente ??= Cliente::query()->find($proyecto->cliente_id);
                if ($cliente?->estado !== 'activo') {
                    throw ValidationException::withMessages(['cliente' => 'El cliente está inactivo: reactívalo antes de abrir un proyecto.']);
                }

                $proyecto->identificador = (string) Str::uuid();
                $proyecto->codigo = $this->siguienteCodigo();
                $proyecto->estado = 'planificado';
                $proyecto->responsable_id = $autor->id;
                $proyecto->revision = 1;
                $proyecto->save();
                AuditLog::record('proyecto_creado', Proyecto::class, $proyecto->id,
                    ['revision' => 1, 'desde_oportunidad' => $proyecto->oportunidad_id !== null], $autor->id);

                return ['proyecto' => $proyecto, 'repetido' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera con otra petición idéntica sobre la misma oportunidad: gana la que confirmó primero.
            $oportunidad = Oportunidad::query()->where('identificador', strtolower((string) ($datos['oportunidad'] ?? '')))->firstOrFail();

            return ['proyecto' => Proyecto::query()->where('oportunidad_id', $oportunidad->id)->firstOrFail(), 'repetido' => true];
        }
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Proyecto {
            $proyecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'proyectos.editar');
            $this->comprobarRevision($request, $proyecto);
            abort_unless($proyecto->esActivo(), 409, 'Un proyecto cerrado no se edita.');

            $proyecto->fill(array_intersect_key($datos, array_flip(['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto'])));
            if (! $proyecto->isDirty()) {
                return $proyecto;
            }

            $proyecto->revision++;
            $proyecto->save();
            AuditLog::record('proyecto_actualizado', Proyecto::class, $proyecto->id, ['revision' => $proyecto->revision], $autor->id);

            return $proyecto;
        });
    }

    public function cambiarEstado(Request $request, string $identificador, string $estado): Proyecto
    {
        if (! in_array($estado, Proyecto::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no permitido.']);
        }

        return DB::transaction(function () use ($request, $identificador, $estado): Proyecto {
            $proyecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'proyectos.editar');
            $this->comprobarRevision($request, $proyecto);

            if ($proyecto->estado === $estado) {
                return $proyecto;
            }
            abort_unless(in_array($estado, Proyecto::TRANSICIONES[$proyecto->estado] ?? [], true), 409, 'Transición no permitida desde el estado actual.');

            $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
            $anterior = $proyecto->estado;
            $proyecto->estado = $estado;
            if ($estado === 'en_curso' && $proyecto->fecha_inicio === null) {
                $proyecto->fecha_inicio = $hoy;
            }
            $proyecto->fecha_fin_real = $estado === 'completado' ? $hoy : null;
            $proyecto->revision++;
            $proyecto->save();
            AuditLog::record('proyecto_estado_cambiado', Proyecto::class, $proyecto->id,
                ['revision' => $proyecto->revision, 'anterior' => $anterior, 'estado' => $estado], $autor->id);

            return $proyecto;
        });
    }

    public function asignarResponsable(Request $request, string $identificador, ?int $responsableId): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $responsableId): Proyecto {
            $proyecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'proyectos.editar');
            $this->comprobarRevision($request, $proyecto);

            if ($responsableId !== null) {
                $responsable = User::query()->find($responsableId);
                if (! $responsable instanceof User || ! $responsable->hasRole('admin') || ! $responsable->can('proyectos.ver')) {
                    throw ValidationException::withMessages(['responsable_id' => 'El responsable no es válido.']);
                }
            }
            if ($proyecto->responsable_id === $responsableId) {
                return $proyecto;
            }
            $proyecto->responsable_id = $responsableId;
            $proyecto->revision++;
            $proyecto->save();
            AuditLog::record('proyecto_responsable_actualizado', Proyecto::class, $proyecto->id, ['revision' => $proyecto->revision], $autor->id);

            return $proyecto;
        });
    }

    /** @param array<string, mixed> $datos `nombre` y `fecha_objetivo` */
    public function agregarHito(Request $request, string $identificador, array $datos): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Proyecto {
            $proyecto = $this->bloquearActivo($request, $identificador, $autor);
            if ($proyecto->hitos()->count() >= Proyecto::MAX_HITOS) {
                throw ValidationException::withMessages(['nombre' => 'Un proyecto admite hasta '.Proyecto::MAX_HITOS.' hitos.']);
            }

            $hito = new ProyectoHito(array_intersect_key($datos, array_flip(['nombre', 'fecha_objetivo'])));
            $hito->identificador = (string) Str::uuid();
            $hito->proyecto_id = $proyecto->id;
            $hito->orden = ((int) $proyecto->hitos()->max('orden')) + 1;
            $hito->save();

            return $this->avanzar($proyecto, 'hito_agregado', $autor);
        });
    }

    /** @param array<string, mixed> $datos `nombre` y `fecha_objetivo` */
    public function actualizarHito(Request $request, string $identificador, string $hitoUuid, array $datos): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $hitoUuid, $datos): Proyecto {
            $proyecto = $this->bloquearActivo($request, $identificador, $autor);
            $hito = $this->hito($proyecto, $hitoUuid);
            $hito->fill(array_intersect_key($datos, array_flip(['nombre', 'fecha_objetivo'])));
            if (! $hito->isDirty()) {
                return $proyecto;
            }
            $hito->save();

            return $this->avanzar($proyecto, 'hito_actualizado', $autor);
        });
    }

    /** Marca o desmarca un hito como completado. Repetir el estado actual no cambia nada. */
    public function completarHito(Request $request, string $identificador, string $hitoUuid, bool $completado): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $hitoUuid, $completado): Proyecto {
            $proyecto = $this->bloquearActivo($request, $identificador, $autor);
            $hito = $this->hito($proyecto, $hitoUuid);
            if (($hito->completado_at !== null) === $completado) {
                return $proyecto;
            }
            $hito->completado_at = $completado ? now() : null;
            $hito->save();

            return $this->avanzar($proyecto, $completado ? 'hito_completado' : 'hito_reabierto', $autor);
        });
    }

    public function eliminarHito(Request $request, string $identificador, string $hitoUuid): Proyecto
    {
        return DB::transaction(function () use ($request, $identificador, $hitoUuid): Proyecto {
            $proyecto = $this->bloquearActivo($request, $identificador, $autor);
            $this->hito($proyecto, $hitoUuid)->delete();

            return $this->avanzar($proyecto, 'hito_eliminado', $autor);
        });
    }

    private function avanzar(Proyecto $proyecto, string $accion, User $autor): Proyecto
    {
        $proyecto->revision++;
        $proyecto->save();
        AuditLog::record($accion, Proyecto::class, $proyecto->id, ['revision' => $proyecto->revision], $autor->id);

        return $proyecto;
    }

    private function hito(Proyecto $proyecto, string $uuid): ProyectoHito
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $uuid) === 1, 404);

        return $proyecto->hitos()->where('identificador', strtolower($uuid))->firstOrFail();
    }

    /** Siguiente `PRY-AAAA-NNNN` del año de Lima, con la fila del contador bloqueada hasta el final de la transacción. */
    private function siguienteCodigo(): string
    {
        $anio = (int) CarbonImmutable::now('America/Lima')->format('Y');
        DB::table('secuencias_codigo')->insertOrIgnore(['tipo' => 'PRY', 'anio' => $anio, 'ultimo' => 0]);
        $fila = DB::table('secuencias_codigo')->where('tipo', 'PRY')->where('anio', $anio)->lockForUpdate()->first();
        $numero = ((int) $fila->ultimo) + 1;
        DB::table('secuencias_codigo')->where('id', $fila->id)->update(['ultimo' => $numero]);

        return sprintf('PRY-%d-%04d', $anio, $numero);
    }

    /** Bloquea el proyecto, autoriza la edición, comprueba la revisión y exige que siga activo. */
    private function bloquearActivo(Request $request, string $identificador, ?User &$autor): Proyecto
    {
        $proyecto = $this->bloquear($identificador);
        $autor = $this->autorizar($request, 'proyectos.editar');
        $this->comprobarRevision($request, $proyecto);
        abort_unless($proyecto->esActivo(), 409, 'Un proyecto cerrado no se edita.');

        return $proyecto;
    }

    private function bloquear(string $identificador): Proyecto
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Proyecto::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, Proyecto $proyecto): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle del proyecto.');
        if (! preg_match('/\A"proyecto-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($proyecto->etag(), $revision), 412, 'El proyecto cambió. Relee y revisa antes de continuar.');
    }
}
