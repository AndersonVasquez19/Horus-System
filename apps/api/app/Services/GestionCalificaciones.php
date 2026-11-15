<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Calificacion;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Matricula;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Evaluaciones, notas y cierre de acta de una convocatoria (Ola 3, docs/ola3-diseno-datos.md §4.2).
 *
 * Única implementación de las reglas, siempre dentro de una transacción con la convocatoria bloqueada (orden: convocatoria
 * → matrícula → calificación):
 *  - las evaluaciones se gestionan mientras la convocatoria está vigente; la suma de pesos no pasa de 100 (en centésimas
 *    enteras, sin decimales binarios) y la estructura exige y avanza la revisión de la convocatoria;
 *  - solo se califica a matrículas vigentes de la convocatoria y con la convocatoria `en_curso`;
 *  - una nota nueva exige `calificaciones.registrar`; cambiar una ya registrada exige `calificaciones.corregir`, un motivo
 *    y la nota que el usuario creía vigente (si cambió, 409), y deja valor anterior y nuevo en la auditoría;
 *  - el cierre de acta exige pesos que sumen 100 y todas las notas, calcula la nota final ponderada, el resultado y deja la
 *    convocatoria `cerrada`, en una sola transacción e idempotente. Nada se modifica después.
 * La auditoría guarda identificadores, valores y estados, nunca el nombre ni el documento del estudiante.
 */
class GestionCalificaciones
{
    /** Evaluaciones por convocatoria. */
    public const MAX_EVALUACIONES = 20;

    private const CIEN = 10000;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /** @param array<string, mixed> $datos `nombre`, `peso` y opcional `fecha` */
    public function crearEvaluacion(Request $request, string $identificador, array $datos): Evaluacion
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Evaluacion {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'calificaciones.corregir');
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            $this->exigirEditable($convocatoria);

            if ($convocatoria->evaluaciones()->count() >= self::MAX_EVALUACIONES) {
                throw ValidationException::withMessages(['nombre' => 'Una convocatoria admite hasta '.self::MAX_EVALUACIONES.' evaluaciones.']);
            }
            $this->comprobarSuma($convocatoria, self::centesimas($datos['peso']), null);

            $evaluacion = new Evaluacion(array_intersect_key($datos, array_flip(['nombre', 'peso', 'fecha'])));
            $evaluacion->identificador = (string) Str::uuid();
            $evaluacion->convocatoria_id = $convocatoria->id;
            $evaluacion->orden = (int) $convocatoria->evaluaciones()->max('orden') + 1;
            $evaluacion->save();

            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('evaluacion_creada', Evaluacion::class, $evaluacion->id,
                ['convocatoria_id' => $convocatoria->id, 'peso' => (string) $evaluacion->peso, 'revision' => $convocatoria->revision], $autor->id);

            return $evaluacion;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizarEvaluacion(Request $request, string $identificador, string $evaluacionId, array $datos): Evaluacion
    {
        return DB::transaction(function () use ($request, $identificador, $evaluacionId, $datos): Evaluacion {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'calificaciones.corregir');
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            $this->exigirEditable($convocatoria);

            $evaluacion = $this->evaluacionDe($convocatoria, $evaluacionId);
            $this->comprobarSuma($convocatoria, self::centesimas($datos['peso']), (int) $evaluacion->id);

            $evaluacion->fill(array_intersect_key($datos, array_flip(['nombre', 'peso', 'fecha'])));
            if (! $evaluacion->isDirty()) {
                return $evaluacion;
            }
            $evaluacion->save();

            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('evaluacion_modificada', Evaluacion::class, $evaluacion->id,
                ['convocatoria_id' => $convocatoria->id, 'peso' => (string) $evaluacion->peso, 'revision' => $convocatoria->revision], $autor->id);

            return $evaluacion;
        });
    }

    /** Elimina una evaluación sin notas. Una con notas no se borra: se corrige su peso. */
    public function eliminarEvaluacion(Request $request, string $identificador, string $evaluacionId): Convocatoria
    {
        return DB::transaction(function () use ($request, $identificador, $evaluacionId): Convocatoria {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'calificaciones.corregir');
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            $this->exigirEditable($convocatoria);

            $evaluacion = $this->evaluacionDe($convocatoria, $evaluacionId);
            abort_if($evaluacion->calificaciones()->exists(), 409, 'La evaluación ya tiene notas registradas y no se puede eliminar.');
            $evaluacion->delete();

            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('evaluacion_eliminada', Evaluacion::class, $evaluacion->id,
                ['convocatoria_id' => $convocatoria->id, 'revision' => $convocatoria->revision], $autor->id);

            return $convocatoria;
        });
    }

    /**
     * Registra o corrige la nota de una matrícula en una evaluación.
     *
     * @param  int|float|string  $nota  de 0 a 20 con hasta dos decimales
     * @param  int|float|string|null  $notaActual  la nota que el usuario ve (obligatoria al corregir)
     */
    public function registrarNota(
        Request $request,
        string $identificador,
        string $evaluacionId,
        int $matriculaId,
        int|float|string $nota,
        ?string $motivo,
        int|float|string|null $notaActual,
    ): Calificacion {
        return DB::transaction(function () use ($request, $identificador, $evaluacionId, $matriculaId, $nota, $motivo, $notaActual): Calificacion {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            // Entrada: quien no puede ni registrar ni corregir no averigua nada del estado. El permiso exacto se decide
            // abajo, según exista ya la nota.
            abort_unless($request->user()?->fresh()?->canAny(['calificaciones.registrar', 'calificaciones.corregir']) === true, 403);
            abort_unless($convocatoria->estado === 'en_curso', 409, 'Las notas se registran mientras la convocatoria está en curso.');
            $evaluacion = $this->evaluacionDe($convocatoria, $evaluacionId);

            $matricula = Matricula::query()->lockForUpdate()
                ->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'anulada')->find($matriculaId);
            if ($matricula === null) {
                throw ValidationException::withMessages(['matricula_id' => 'La matrícula no está vigente en esta convocatoria.']);
            }

            $nueva = self::centesimas($nota);
            $existente = Calificacion::query()->lockForUpdate()
                ->where('matricula_id', $matricula->id)->where('evaluacion_id', $evaluacion->id)->first();

            if ($existente === null) {
                $autor = $this->convocatorias->autorizar($request, 'calificaciones.registrar');
                $calificacion = new Calificacion(['nota' => $this->formato($nueva)]);
                $calificacion->matricula_id = $matricula->id;
                $calificacion->evaluacion_id = $evaluacion->id;
                $calificacion->registrado_por = $autor->id;
                $calificacion->save();
                AuditLog::record('nota_registrada', Calificacion::class, $calificacion->id,
                    ['evaluacion_id' => $evaluacion->id, 'matricula_id' => $matricula->id, 'nota' => $this->formato($nueva)], $autor->id);

                return $calificacion;
            }

            $autor = $this->convocatorias->autorizar($request, 'calificaciones.corregir');
            $anterior = self::centesimas($existente->nota);
            if ($notaActual === null || self::centesimas($notaActual) !== $anterior) {
                abort(409, 'La nota cambió mientras la editabas. Recarga y revisa antes de corregirla.');
            }
            if ($nueva === $anterior) {
                return $existente;
            }
            $motivo = trim((string) $motivo);
            if ($motivo === '') {
                throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la corrección.']);
            }

            $existente->nota = $nueva / 100;
            $existente->registrado_por = $autor->id;
            $existente->save();
            AuditLog::record('nota_corregida', Calificacion::class, $existente->id, [
                'evaluacion_id' => $evaluacion->id, 'matricula_id' => $matricula->id,
                'antes' => $this->formato($anterior), 'despues' => $this->formato($nueva), 'motivo' => $motivo,
            ], $autor->id);

            return $existente;
        });
    }

    /**
     * Cierre de acta: nota final ponderada y resultado de cada matrícula vigente y convocatoria `cerrada`. Repetirlo sobre una
     * convocatoria ya cerrada no cambia nada.
     */
    public function cerrarActa(Request $request, string $identificador): Convocatoria
    {
        return DB::transaction(function () use ($request, $identificador): Convocatoria {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'convocatorias.cerrar');
            if ($convocatoria->estado === 'cerrada') {
                return $convocatoria;
            }
            $this->convocatorias->comprobarRevision($request, $convocatoria);
            abort_unless($convocatoria->estado === 'en_curso', 409, 'El acta se cierra con la convocatoria en curso.');

            $evaluaciones = $convocatoria->evaluaciones()->get();
            if ($evaluaciones->isEmpty() || $evaluaciones->sum(fn (Evaluacion $e): int => self::centesimas($e->peso)) !== self::CIEN) {
                throw ValidationException::withMessages(['pesos' => 'Los pesos de las evaluaciones deben sumar exactamente 100 para cerrar el acta.']);
            }

            $matriculas = Matricula::query()->lockForUpdate()
                ->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'anulada')->orderBy('id')->get();
            $notas = Calificacion::query()->whereIn('evaluacion_id', $evaluaciones->pluck('id'))
                ->whereIn('matricula_id', $matriculas->pluck('id'))->get()->groupBy('matricula_id');

            $faltan = 0;
            $finales = [];
            foreach ($matriculas as $matricula) {
                $porEvaluacion = ($notas[$matricula->id] ?? collect())->keyBy('evaluacion_id');
                $suma = 0;
                foreach ($evaluaciones as $evaluacion) {
                    $calificacion = $porEvaluacion[$evaluacion->id] ?? null;
                    if ($calificacion === null) {
                        $faltan++;

                        continue;
                    }
                    $suma += self::centesimas($calificacion->nota) * self::centesimas($evaluacion->peso);
                }
                // nota (centésimas) × peso (centésimas) / 10000 = nota final en centésimas, redondeada al más cercano.
                $finales[$matricula->id] = intdiv($suma * 2 + self::CIEN, self::CIEN * 2);
            }
            if ($faltan > 0) {
                throw ValidationException::withMessages(['notas' => "Faltan {$faltan} notas por registrar para cerrar el acta."]);
            }

            $aprobados = 0;
            foreach ($finales as $matriculaId => $final) {
                $aprobado = $final >= Calificacion::NOTA_APROBATORIA * 100;
                $aprobados += $aprobado ? 1 : 0;
                Matricula::query()->whereKey($matriculaId)->update([
                    'nota_final' => $this->formato($final),
                    'resultado' => $aprobado ? 'aprobado' : 'desaprobado',
                ]);
            }

            $convocatoria->estado = 'cerrada';
            $convocatoria->revision++;
            $convocatoria->save();
            AuditLog::record('acta_cerrada', Convocatoria::class, $convocatoria->id,
                ['revision' => $convocatoria->revision, 'matriculas' => count($finales), 'aprobados' => $aprobados], $autor->id);

            return $convocatoria;
        });
    }

    /** Centésimas enteras de un valor decimal (sin acumular errores de coma flotante). */
    public static function centesimas(int|float|string|null $valor): int
    {
        return (int) round((float) $valor * 100);
    }

    private function formato(int $centesimas): string
    {
        return number_format($centesimas / 100, 2, '.', '');
    }

    private function exigirEditable(Convocatoria $convocatoria): void
    {
        abort_unless($convocatoria->estaVigente(), 409, 'Una convocatoria cerrada o cancelada no se edita.');
    }

    private function evaluacionDe(Convocatoria $convocatoria, string $identificador): Evaluacion
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return $convocatoria->evaluaciones()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function comprobarSuma(Convocatoria $convocatoria, int $peso, ?int $excluirId): void
    {
        $otras = $convocatoria->evaluaciones()->when($excluirId !== null, fn ($q) => $q->where('id', '!=', $excluirId))->get()
            ->sum(fn (Evaluacion $e): int => self::centesimas($e->peso));
        if ($otras + $peso > self::CIEN) {
            $libre = $this->formato(self::CIEN - $otras);
            throw ValidationException::withMessages(['peso' => "La suma de pesos no puede pasar de 100: quedan {$libre} por repartir."]);
        }
    }
}
