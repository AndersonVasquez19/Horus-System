<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaHorario;
use App\Models\Curso;
use App\Models\User;
use App\Support\CodigoSecuencial;
use App\Support\HoraLocal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Convocatorias y horarios del panel académico (Ola 3, docs/ola3-diseno-datos.md §4.1).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (428/412), permiso releído con datos
 * vigentes, estados cerrados (`cerrada` y `cancelada` exigen `convocatorias.cerrar`), código `CNV-AAAA-NNNN` bajo bloqueo,
 * cupos que nunca bajan de lo ocupado y horarios sin solapamiento. La auditoría guarda revisión y estados, nunca textos.
 */
class GestionConvocatorias
{
    /** @param array<string, mixed> $datos `curso_id`, `modalidad`, fechas, `cupos_maximos` y opcional `nombre` */
    public function crear(Request $request, array $datos): Convocatoria
    {
        return DB::transaction(function () use ($request, $datos): Convocatoria {
            $autor = $this->autorizar($request, 'convocatorias.crear');

            $curso = Curso::withTrashed()->lockForUpdate()->find((int) ($datos['curso_id'] ?? 0));
            if ($curso === null || $curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages(['curso_id' => 'El curso seleccionado no está disponible.']);
            }

            $convocatoria = new Convocatoria(array_intersect_key($datos, array_flip(['nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos'])));
            $convocatoria->identificador = (string) Str::uuid();
            $convocatoria->codigo = CodigoSecuencial::siguiente('CNV');
            $convocatoria->curso_id = $curso->id;
            $convocatoria->estado = 'borrador';
            $convocatoria->cupos_disponibles = $convocatoria->cupos_maximos;
            $convocatoria->revision = 1;
            $convocatoria->save();
            AuditLog::record('convocatoria_creada', Convocatoria::class, $convocatoria->id, ['revision' => 1], $autor->id);

            return $convocatoria;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): Convocatoria
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Convocatoria {
            $convocatoria = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'convocatorias.editar');
            $this->comprobarRevision($request, $convocatoria);
            abort_unless($convocatoria->estaVigente(), 409, 'Una convocatoria cerrada o cancelada no se edita.');

            $maximoAnterior = $convocatoria->cupos_maximos;
            $convocatoria->fill(array_intersect_key($datos, array_flip(['nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos'])));
            if ($convocatoria->cupos_maximos !== $maximoAnterior) {
                // Los cupos disponibles se mueven con el máximo; nunca quedan por debajo de los ya ocupados.
                $ocupados = $maximoAnterior - $convocatoria->cupos_disponibles;
                if ($convocatoria->cupos_maximos < $ocupados) {
                    throw ValidationException::withMessages(['cupos_maximos' => "Ya hay {$ocupados} cupos ocupados: el máximo no puede ser menor."]);
                }
                $convocatoria->cupos_disponibles = $convocatoria->cupos_maximos - $ocupados;
            }
            if (! $convocatoria->isDirty()) {
                return $convocatoria;
            }

            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('convocatoria_actualizada', Convocatoria::class, $convocatoria->id, ['revision' => $convocatoria->revision], $autor->id);

            return $convocatoria;
        });
    }

    public function cambiarEstado(Request $request, string $identificador, string $estado): Convocatoria
    {
        if (! in_array($estado, Convocatoria::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no permitido.']);
        }

        return DB::transaction(function () use ($request, $identificador, $estado): Convocatoria {
            $convocatoria = $this->bloquear($identificador);
            $autor = $this->autorizar($request, in_array($estado, ['cerrada', 'cancelada'], true) ? 'convocatorias.cerrar' : 'convocatorias.editar');
            $this->comprobarRevision($request, $convocatoria);

            if ($convocatoria->estado === $estado) {
                return $convocatoria;
            }
            abort_unless(in_array($estado, Convocatoria::TRANSICIONES[$convocatoria->estado] ?? [], true), 409, 'Transición no permitida desde el estado actual.');
            if ($estado === 'abierta' && $convocatoria->fecha_fin->toDateString() < HoraLocal::hoy()) {
                throw ValidationException::withMessages(['estado' => 'No se abre una convocatoria cuyo periodo ya terminó.']);
            }

            // Con evaluaciones, el cierre calcula notas finales y resultados: solo lo hace el cierre de acta.
            abort_if($estado === 'cerrada' && $convocatoria->evaluaciones()->exists(), 409,
                'Esta convocatoria tiene evaluaciones: usa el cierre de acta.');

            // Cancelar con matrículas vigentes dejaría estudiantes matriculados en una convocatoria inexistente.
            abort_if($estado === 'cancelada' && $convocatoria->cuposOcupados() > 0, 409,
                'La convocatoria tiene matrículas vigentes: anúlalas o cámbialas antes de cancelarla.');

            $anterior = $convocatoria->estado;
            $convocatoria->estado = $estado;
            $convocatoria->revision++;
            $convocatoria->save();
            $accion = match ($estado) {
                'abierta' => 'convocatoria_abierta',
                'cerrada' => 'convocatoria_cerrada',
                'cancelada' => 'convocatoria_cancelada',
                default => 'convocatoria_estado_cambiado',
            };
            AuditLog::record($accion, Convocatoria::class, $convocatoria->id,
                ['revision' => $convocatoria->revision, 'anterior' => $anterior, 'estado' => $estado], $autor->id);

            return $convocatoria;
        });
    }

    /**
     * Sustituye todos los bloques de horario de una vez (atómico). No admite dos bloques del mismo día que se solapen.
     *
     * @param  list<array<string, mixed>>  $horarios
     */
    public function sustituirHorarios(Request $request, string $identificador, array $horarios): Convocatoria
    {
        $this->comprobarSolapes($horarios);

        return DB::transaction(function () use ($request, $identificador, $horarios): Convocatoria {
            $convocatoria = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'convocatorias.editar');
            $this->comprobarRevision($request, $convocatoria);
            abort_unless($convocatoria->estaVigente(), 409, 'Una convocatoria cerrada o cancelada no se edita.');

            $convocatoria->horarios()->delete();
            foreach ($horarios as $bloque) {
                $horario = new ConvocatoriaHorario(array_intersect_key($bloque, array_flip(['dia_semana', 'hora_inicio', 'hora_fin', 'lugar', 'docente'])));
                $horario->convocatoria_id = $convocatoria->id;
                $horario->save();
            }

            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('horario_modificado', Convocatoria::class, $convocatoria->id,
                ['revision' => $convocatoria->revision, 'bloques' => count($horarios)], $autor->id);

            return $convocatoria;
        });
    }

    /** @param list<array<string, mixed>> $horarios */
    private function comprobarSolapes(array $horarios): void
    {
        if (count($horarios) > Convocatoria::MAX_HORARIOS) {
            throw ValidationException::withMessages(['horarios' => 'Una convocatoria admite hasta '.Convocatoria::MAX_HORARIOS.' bloques de horario.']);
        }
        $porDia = [];
        foreach ($horarios as $i => $bloque) {
            $inicio = (string) $bloque['hora_inicio'];
            $fin = (string) $bloque['hora_fin'];
            foreach ($porDia[$bloque['dia_semana']] ?? [] as [$otroInicio, $otroFin]) {
                // Dos intervalos se solapan si cada uno empieza antes de que el otro termine (extremos que se tocan no solapan).
                if ($inicio < $otroFin && $otroInicio < $fin) {
                    throw ValidationException::withMessages(["horarios.{$i}.hora_inicio" => 'Este bloque se solapa con otro del mismo día.']);
                }
            }
            $porDia[$bloque['dia_semana']][] = [$inicio, $fin];
        }
    }

    /** Bloquea la fila de la convocatoria (dentro de una transacción). Lo reutiliza {@see GestionCalificaciones}. */
    public function bloquear(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    public function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->can($permiso), 403);

        return $actor;
    }

    public function comprobarRevision(Request $request, Convocatoria $convocatoria): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle de la convocatoria.');
        if (! preg_match('/\A"convocatoria-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($convocatoria->etag(), $revision), 412, 'La convocatoria cambió. Relee y revisa antes de continuar.');
    }
}
