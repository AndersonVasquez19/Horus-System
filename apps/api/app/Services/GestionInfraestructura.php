<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Aula;
use App\Models\Convocatoria;
use App\Models\PlataformaVirtual;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Infraestructura académica (Horus Academic, Fase 3): sedes, aulas y plataformas virtuales, y su asignación a las ediciones
 * (`convocatorias`). Única implementación de las reglas:
 *
 *  - toda escritura es una transacción con la fila bloqueada y el permiso releído con datos vigentes (`infraestructura.gestionar`;
 *    asignar a una edición exige `convocatorias.editar`);
 *  - nada se elimina: se desactiva, y no se desactiva ni se reduce lo que usan ediciones vigentes;
 *  - una edición presencial lleva sede y aula (del mismo local, activos, con capacidad ≥ cupos); una virtual, plataforma activa;
 *  - la auditoría guarda ids y estados, nunca textos libres.
 */
class GestionInfraestructura
{
    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    // ---- Sedes -------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $datos `codigo`, `nombre`, `direccion` y opcional `ciudad` */
    public function crearSede(Request $request, array $datos): Sede
    {
        return DB::transaction(function () use ($request, $datos): Sede {
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $codigo = $this->codigo($datos['codigo']);
            $this->comprobarUnico(Sede::query()->where('codigo', $codigo), 'codigo', 'Ya existe una sede con ese código.');

            $sede = new Sede(array_intersect_key($datos, array_flip(['nombre', 'direccion', 'ciudad'])));
            $sede->identificador = (string) Str::uuid();
            $sede->codigo = $codigo;
            $sede->estado = 'activo';
            if (! isset($datos['ciudad'])) {
                $sede->ciudad = 'Cajamarca';
            }
            $sede->save();
            AuditLog::record('sede_creada', Sede::class, $sede->id, ['estado' => 'activo'], $autor->id);

            return $sede;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizarSede(Request $request, string $identificador, array $datos): Sede
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Sede {
            $sede = $this->bloquear(Sede::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $sede->fill(array_intersect_key($datos, array_flip(['nombre', 'direccion', 'ciudad'])));
            if ($sede->isDirty()) {
                $sede->save();
                AuditLog::record('sede_actualizada', Sede::class, $sede->id, [], $autor->id);
            }

            return $sede;
        });
    }

    public function cambiarEstadoSede(Request $request, string $identificador, string $estado): Sede
    {
        $this->validarEstado($estado);

        return DB::transaction(function () use ($request, $identificador, $estado): Sede {
            $sede = $this->bloquear(Sede::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            if ($sede->estado === $estado) {
                return $sede;
            }
            abort_if($estado === 'inactivo' && $this->usadaPorEdicionesVigentes('sede_id', $sede->id), 409,
                'La sede está asignada a ediciones vigentes: cámbiala en esas ediciones antes de desactivarla.');
            $sede->estado = $estado;
            $sede->save();
            AuditLog::record('sede_estado_cambiado', Sede::class, $sede->id, ['estado' => $estado], $autor->id);

            return $sede;
        });
    }

    // ---- Aulas -------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $datos `sede` (UUID), `codigo`, `nombre`, `capacidad` */
    public function crearAula(Request $request, array $datos): Aula
    {
        return DB::transaction(function () use ($request, $datos): Aula {
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $sede = $this->bloquear(Sede::query(), (string) $datos['sede']);
            if (! $sede->estaActiva()) {
                throw ValidationException::withMessages(['sede' => 'La sede seleccionada está inactiva.']);
            }
            $codigo = $this->codigo($datos['codigo']);
            $this->comprobarUnico(Aula::query()->where('sede_id', $sede->id)->where('codigo', $codigo), 'codigo', 'Ya existe un aula con ese código en la sede.');

            $aula = new Aula(array_intersect_key($datos, array_flip(['nombre', 'capacidad'])));
            $aula->identificador = (string) Str::uuid();
            $aula->sede_id = $sede->id;
            $aula->codigo = $codigo;
            $aula->estado = 'activo';
            $aula->save();
            AuditLog::record('aula_creada', Aula::class, $aula->id, ['sede_id' => $sede->id, 'capacidad' => $aula->capacidad], $autor->id);

            return $aula;
        });
    }

    /** @param array<string, mixed> $datos `nombre` y `capacidad`; la sede y el código no cambian */
    public function actualizarAula(Request $request, string $identificador, array $datos): Aula
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Aula {
            $aula = $this->bloquear(Aula::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $aula->fill(array_intersect_key($datos, array_flip(['nombre', 'capacidad'])));
            if (! $aula->isDirty()) {
                return $aula;
            }

            if ($aula->isDirty('capacidad')) {
                $mayorCupo = (int) Convocatoria::query()->where('aula_id', $aula->id)->whereIn('estado', Convocatoria::VIGENTES)->max('cupos_maximos');
                if ($aula->capacidad < $mayorCupo) {
                    throw ValidationException::withMessages(['capacidad' => "Hay ediciones vigentes con hasta {$mayorCupo} cupos en esta aula: la capacidad no puede ser menor."]);
                }
            }
            $aula->save();
            AuditLog::record('aula_actualizada', Aula::class, $aula->id, ['capacidad' => $aula->capacidad], $autor->id);

            return $aula;
        });
    }

    public function cambiarEstadoAula(Request $request, string $identificador, string $estado): Aula
    {
        $this->validarEstado($estado);

        return DB::transaction(function () use ($request, $identificador, $estado): Aula {
            $aula = $this->bloquear(Aula::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            if ($aula->estado === $estado) {
                return $aula;
            }
            abort_if($estado === 'inactivo' && $this->usadaPorEdicionesVigentes('aula_id', $aula->id), 409,
                'El aula está asignada a ediciones vigentes: cámbiala en esas ediciones antes de desactivarla.');
            if ($estado === 'activo' && ! Sede::query()->whereKey($aula->sede_id)->where('estado', 'activo')->exists()) {
                throw ValidationException::withMessages(['estado' => 'La sede del aula está inactiva: actívala primero.']);
            }
            $aula->estado = $estado;
            $aula->save();
            AuditLog::record('aula_estado_cambiado', Aula::class, $aula->id, ['estado' => $estado], $autor->id);

            return $aula;
        });
    }

    // ---- Plataformas virtuales ---------------------------------------------------------------------------------------

    /** @param array<string, mixed> $datos `nombre` y `tipo` */
    public function crearPlataforma(Request $request, array $datos): PlataformaVirtual
    {
        return DB::transaction(function () use ($request, $datos): PlataformaVirtual {
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $this->comprobarNombrePlataforma((string) $datos['nombre'], null);

            $plataforma = new PlataformaVirtual(array_intersect_key($datos, array_flip(['nombre', 'tipo'])));
            $plataforma->identificador = (string) Str::uuid();
            $plataforma->estado = 'activo';
            $plataforma->save();
            AuditLog::record('plataforma_creada', PlataformaVirtual::class, $plataforma->id, ['tipo' => $plataforma->tipo], $autor->id);

            return $plataforma;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizarPlataforma(Request $request, string $identificador, array $datos): PlataformaVirtual
    {
        return DB::transaction(function () use ($request, $identificador, $datos): PlataformaVirtual {
            $plataforma = $this->bloquear(PlataformaVirtual::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            $plataforma->fill(array_intersect_key($datos, array_flip(['nombre', 'tipo'])));
            if (! $plataforma->isDirty()) {
                return $plataforma;
            }
            if ($plataforma->isDirty('nombre')) {
                $this->comprobarNombrePlataforma($plataforma->nombre, $plataforma->id);
            }
            $plataforma->save();
            AuditLog::record('plataforma_actualizada', PlataformaVirtual::class, $plataforma->id, ['tipo' => $plataforma->tipo], $autor->id);

            return $plataforma;
        });
    }

    public function cambiarEstadoPlataforma(Request $request, string $identificador, string $estado): PlataformaVirtual
    {
        $this->validarEstado($estado);

        return DB::transaction(function () use ($request, $identificador, $estado): PlataformaVirtual {
            $plataforma = $this->bloquear(PlataformaVirtual::query(), $identificador);
            $autor = $this->autorizar($request, 'infraestructura.gestionar');
            if ($plataforma->estado === $estado) {
                return $plataforma;
            }
            abort_if($estado === 'inactivo' && $this->usadaPorEdicionesVigentes('plataforma_virtual_id', $plataforma->id), 409,
                'La plataforma está asignada a ediciones vigentes: cámbiala en esas ediciones antes de desactivarla.');
            $plataforma->estado = $estado;
            $plataforma->save();
            AuditLog::record('plataforma_estado_cambiado', PlataformaVirtual::class, $plataforma->id, ['estado' => $estado], $autor->id);

            return $plataforma;
        });
    }

    // ---- Asignación a una edición -----------------------------------------------------------------------------------

    /**
     * Asigna (o corrige) la infraestructura de una edición según su modalidad. Presencial: `sede` y `aula` y sin plataforma.
     * Virtual: `plataforma_virtual` y sin sede ni aula. Las modalidades heredadas no admiten asignación.
     *
     * @param  array<string, mixed>  $datos  `sede`, `aula`, `plataforma_virtual` (UUID o null)
     */
    public function asignarAEdicion(Request $request, string $identificador, array $datos): Convocatoria
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Convocatoria {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'convocatorias.editar');
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            abort_unless($convocatoria->estaVigente(), 409, 'Una convocatoria cerrada o cancelada no se edita.');

            [$sedeId, $aulaId, $plataformaId] = $this->resolverAsignacion($convocatoria, $datos);

            $cambio = $convocatoria->sede_id !== $sedeId || $convocatoria->aula_id !== $aulaId || $convocatoria->plataforma_virtual_id !== $plataformaId;
            if (! $cambio) {
                return $convocatoria;
            }

            $convocatoria->sede_id = $sedeId;
            $convocatoria->aula_id = $aulaId;
            $convocatoria->plataforma_virtual_id = $plataformaId;
            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('convocatoria_infraestructura_asignada', Convocatoria::class, $convocatoria->id,
                ['revision' => $convocatoria->revision, 'sede_id' => $sedeId, 'aula_id' => $aulaId, 'plataforma_virtual_id' => $plataformaId], $autor->id);

            return $convocatoria;
        });
    }

    /**
     * Valida y bloquea lo asignado según la modalidad de la edición.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: int|null, 1: int|null, 2: int|null} ids de sede, aula y plataforma
     */
    private function resolverAsignacion(Convocatoria $convocatoria, array $datos): array
    {
        $sede = $this->uuidONulo($datos['sede'] ?? null);
        $aula = $this->uuidONulo($datos['aula'] ?? null);
        $plataforma = $this->uuidONulo($datos['plataforma_virtual'] ?? null);

        if ($convocatoria->modalidad === 'presencial') {
            if ($sede === null || $aula === null) {
                throw ValidationException::withMessages(['sede' => 'Una edición presencial requiere sede y aula.']);
            }
            if ($plataforma !== null) {
                throw ValidationException::withMessages(['plataforma_virtual' => 'Una edición presencial no lleva plataforma virtual.']);
            }
            $sedeModelo = Sede::query()->lockForUpdate()->where('identificador', $sede)->first();
            $aulaModelo = Aula::query()->lockForUpdate()->where('identificador', $aula)->first();
            if ($sedeModelo === null) {
                throw ValidationException::withMessages(['sede' => 'La sede seleccionada no existe.']);
            }
            if ($aulaModelo === null || $aulaModelo->sede_id !== $sedeModelo->id) {
                throw ValidationException::withMessages(['aula' => 'El aula no existe o no pertenece a la sede seleccionada.']);
            }
            if (! $sedeModelo->estaActiva() || ! $aulaModelo->estaActiva()) {
                throw ValidationException::withMessages(['aula' => 'La sede y el aula deben estar activas.']);
            }
            if ($convocatoria->cupos_maximos > $aulaModelo->capacidad) {
                throw ValidationException::withMessages(['aula' => "El aula tiene capacidad para {$aulaModelo->capacidad} y la edición admite {$convocatoria->cupos_maximos} cupos."]);
            }

            return [$sedeModelo->id, $aulaModelo->id, null];
        }

        if ($convocatoria->modalidad === 'virtual') {
            if ($sede !== null || $aula !== null) {
                throw ValidationException::withMessages(['sede' => 'Una edición virtual no lleva sede ni aula.']);
            }
            if ($plataforma === null) {
                throw ValidationException::withMessages(['plataforma_virtual' => 'Una edición virtual requiere una plataforma virtual.']);
            }
            $plataformaModelo = PlataformaVirtual::query()->lockForUpdate()->where('identificador', $plataforma)->first();
            if ($plataformaModelo === null) {
                throw ValidationException::withMessages(['plataforma_virtual' => 'La plataforma seleccionada no existe.']);
            }
            if (! $plataformaModelo->estaActiva()) {
                throw ValidationException::withMessages(['plataforma_virtual' => 'La plataforma seleccionada está inactiva.']);
            }

            return [null, null, $plataformaModelo->id];
        }

        throw ValidationException::withMessages(['modalidad' => 'Las ediciones semipresencial e híbrida son históricas y no admiten asignación de infraestructura.']);
    }

    // ---- Utilidades --------------------------------------------------------------------------------------------------

    public function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->can($permiso), 403);

        return $actor;
    }

    /**
     * @template TModelo of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModelo>  $consulta
     * @return TModelo
     */
    private function bloquear(Builder $consulta, string $identificador)
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return $consulta->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function codigo(mixed $valor): string
    {
        return strtoupper(trim((string) $valor));
    }

    /** @param Builder<Model> $consulta */
    private function comprobarUnico(Builder $consulta, string $campo, string $mensaje): void
    {
        if ($consulta->exists()) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }
    }

    private function comprobarNombrePlataforma(string $nombre, ?int $excepto): void
    {
        $consulta = PlataformaVirtual::query()->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($nombre))]);
        if ($excepto !== null) {
            $consulta->whereKeyNot($excepto);
        }
        $this->comprobarUnico($consulta, 'nombre', 'Ya existe una plataforma con ese nombre.');
    }

    private function validarEstado(string $estado): void
    {
        if (! in_array($estado, Sede::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no permitido.']);
        }
    }

    private function uuidONulo(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (! is_string($valor) || preg_match('/\A[0-9a-f-]{36}\z/i', $valor) !== 1) {
            throw ValidationException::withMessages(['identificador' => 'Un identificador de infraestructura no es válido.']);
        }

        return strtolower($valor);
    }

    private function usadaPorEdicionesVigentes(string $columna, int $id): bool
    {
        return Convocatoria::query()->where($columna, $id)->whereIn('estado', Convocatoria::VIGENTES)->exists();
    }
}
