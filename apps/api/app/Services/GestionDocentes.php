<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Docente;
use App\Models\User;
use App\Support\DniUnico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Docentes (Horus Academic, Fase 4) y su asignación a ediciones. Única implementación de las reglas:
 *
 *  - identificados SOLO por DNI (8 dígitos, texto, único, inmutable), y un mismo DNI no puede ser estudiante y docente a la vez
 *    ({@see DniUnico}); los eliminados lógicamente conservan su DNI;
 *  - toda escritura es una transacción con la fila bloqueada y el permiso releído con datos vigentes;
 *  - no se elimina ni se inactiva a un docente asignado a ediciones vigentes;
 *  - orden de bloqueo al asignar: edición → docentes por id (nunca al revés);
 *  - la auditoría guarda ids y NOMBRES de campos, jamás DNI, correo, teléfono ni nombres de personas.
 */
class GestionDocentes
{
    /** Máximo de docentes por edición. */
    public const MAX_POR_EDICION = 10;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /** @param array<string, mixed> $datos `dni`, `nombres`, `apellidos` y opcionales `email`, `telefono`, `especialidad` */
    public function crear(Request $request, array $datos): Docente
    {
        return DB::transaction(function () use ($request, $datos): Docente {
            $autor = $this->autorizar($request, 'docentes.crear');
            $dni = (string) $datos['dni'];

            DniUnico::comprobar($dni, DniUnico::COMO_DOCENTE);
            if (Docente::withTrashed()->where('dni', $dni)->exists()) {
                throw ValidationException::withMessages(['dni' => 'Ya existe un docente registrado con este DNI.']);
            }
            $this->comprobarCorreo($datos['email'] ?? null, null);

            $docente = new Docente(array_intersect_key($datos, array_flip(['nombres', 'apellidos', 'email', 'telefono', 'especialidad'])));
            $docente->identificador = (string) Str::uuid();
            $docente->dni = $dni;
            $docente->estado = 'activo';
            $docente->save();
            AuditLog::record('docente_creado', Docente::class, $docente->id, ['campos' => array_keys(array_intersect_key($datos, array_flip(['nombres', 'apellidos', 'email', 'telefono', 'especialidad'])))], $autor->id);

            return $docente;
        });
    }

    /** @param array<string, mixed> $datos campos editables y opcional `estado`; el DNI no cambia */
    public function actualizar(Request $request, string $identificador, array $datos): Docente
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Docente {
            $docente = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'docentes.editar');
            abort_if($docente->trashed(), 409, 'Un docente eliminado no se edita: restáuralo primero.');

            $docente->fill(array_intersect_key($datos, array_flip(['nombres', 'apellidos', 'email', 'telefono', 'especialidad'])));
            if (isset($datos['estado'])) {
                $docente->estado = $datos['estado'];
            }
            if (! $docente->isDirty()) {
                return $docente;
            }

            if ($docente->isDirty('email')) {
                $this->comprobarCorreo($docente->email, $docente->id);
            }
            if ($docente->isDirty('estado') && $docente->estado === 'inactivo') {
                abort_if($this->tieneEdicionesVigentes($docente), 409, 'El docente está asignado a ediciones vigentes: quítalo de ellas antes de inactivarlo.');
            }

            $cambiados = array_keys($docente->getDirty());
            $docente->save();
            AuditLog::record('docente_actualizado', Docente::class, $docente->id, ['campos' => $cambiados], $autor->id);

            return $docente;
        });
    }

    public function eliminar(Request $request, string $identificador): Docente
    {
        return DB::transaction(function () use ($request, $identificador): Docente {
            $docente = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'docentes.eliminar');
            if ($docente->trashed()) {
                return $docente;
            }
            abort_if($this->tieneEdicionesVigentes($docente), 409, 'El docente está asignado a ediciones vigentes: quítalo de ellas antes de eliminarlo.');

            $docente->delete();
            AuditLog::record('docente_eliminado', Docente::class, $docente->id, [], $autor->id);

            return $docente;
        });
    }

    public function restaurar(Request $request, string $identificador): Docente
    {
        return DB::transaction(function () use ($request, $identificador): Docente {
            $docente = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'docentes.restaurar');
            if (! $docente->trashed()) {
                return $docente;
            }

            $docente->restore();
            AuditLog::record('docente_restaurado', Docente::class, $docente->id, [], $autor->id);

            return $docente;
        });
    }

    /**
     * Reemplaza los docentes de una edición. A lo sumo un `principal`; solo docentes activos y no eliminados; edición vigente.
     * Repetir la misma asignación no avanza la revisión ni audita.
     *
     * @param  list<array{docente: string, rol: string}>  $asignaciones
     */
    public function asignarAEdicion(Request $request, string $identificador, array $asignaciones): Convocatoria
    {
        $this->validarAsignaciones($asignaciones);

        return DB::transaction(function () use ($request, $identificador, $asignaciones): Convocatoria {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'convocatorias.editar');
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            abort_unless($convocatoria->estaVigente(), 409, 'Una convocatoria cerrada o cancelada no se edita.');

            $uuids = array_map(static fn (array $a): string => strtolower($a['docente']), $asignaciones);
            $docentes = $uuids === [] ? collect() : Docente::query()->lockForUpdate()->whereIn('identificador', $uuids)->orderBy('id')->get()->keyBy('identificador');
            $nuevas = [];
            foreach ($asignaciones as $i => $a) {
                $docente = $docentes->get(strtolower($a['docente']));
                if ($docente === null || $docente->trashed()) {
                    throw ValidationException::withMessages(["docentes.{$i}.docente" => 'El docente seleccionado no existe.']);
                }
                if (! $docente->estaActivo()) {
                    throw ValidationException::withMessages(["docentes.{$i}.docente" => 'El docente seleccionado está inactivo.']);
                }
                $nuevas[$docente->id] = $a['rol'];
            }

            $actuales = DB::table('convocatoria_docentes')->where('convocatoria_id', $convocatoria->id)->pluck('rol', 'docente_id')->all();
            ksort($actuales);
            ksort($nuevas);
            if ($actuales === $nuevas) {
                return $convocatoria;
            }

            DB::table('convocatoria_docentes')->where('convocatoria_id', $convocatoria->id)->delete();
            foreach ($nuevas as $docenteId => $rol) {
                DB::table('convocatoria_docentes')->insert(['convocatoria_id' => $convocatoria->id, 'docente_id' => $docenteId, 'rol' => $rol, 'created_at' => now(), 'updated_at' => now()]);
            }
            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('convocatoria_docentes_asignados', Convocatoria::class, $convocatoria->id,
                ['revision' => $convocatoria->revision, 'docentes' => array_keys($nuevas), 'principal' => array_search('principal', $nuevas, true) ?: null], $autor->id);

            return $convocatoria;
        });
    }

    /** @param list<array{docente: string, rol: string}> $asignaciones */
    private function validarAsignaciones(array $asignaciones): void
    {
        if (count($asignaciones) > self::MAX_POR_EDICION) {
            throw ValidationException::withMessages(['docentes' => 'Una edición admite hasta '.self::MAX_POR_EDICION.' docentes.']);
        }
        $vistos = [];
        $principales = 0;
        foreach ($asignaciones as $i => $a) {
            if (! in_array($a['rol'] ?? null, Docente::ROLES, true)) {
                throw ValidationException::withMessages(["docentes.{$i}.rol" => 'El rol debe ser principal o apoyo.']);
            }
            $uuid = strtolower((string) ($a['docente'] ?? ''));
            if (isset($vistos[$uuid])) {
                throw ValidationException::withMessages(["docentes.{$i}.docente" => 'Un docente no puede repetirse en la misma edición.']);
            }
            $vistos[$uuid] = true;
            $principales += $a['rol'] === 'principal' ? 1 : 0;
        }
        if ($principales > 1) {
            throw ValidationException::withMessages(['docentes' => 'Una edición tiene un solo docente principal.']);
        }
    }

    public function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->can($permiso), 403);

        return $actor;
    }

    private function bloquear(string $identificador): Docente
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Docente::withTrashed()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function comprobarCorreo(mixed $email, ?int $excepto): void
    {
        if (! is_string($email) || $email === '') {
            return;
        }
        $consulta = Docente::withTrashed()->whereRaw('lower(email) = ?', [mb_strtolower($email)]);
        if ($excepto !== null) {
            $consulta->whereKeyNot($excepto);
        }
        if ($consulta->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe un docente con este correo.']);
        }
    }

    private function tieneEdicionesVigentes(Docente $docente): bool
    {
        return DB::table('convocatoria_docentes as cd')
            ->join('convocatorias as c', 'c.id', '=', 'cd.convocatoria_id')
            ->where('cd.docente_id', $docente->id)
            ->whereIn('c.estado', Convocatoria::VIGENTES)
            ->whereNull('c.deleted_at')
            ->exists();
    }
}
