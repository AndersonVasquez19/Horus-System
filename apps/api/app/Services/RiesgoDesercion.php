<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Riesgo de deserción de los matriculados en ediciones en curso (Fase 3.4). Puntaje simple y explicable, sumando factores:
 *  - faltas seguidas más recientes: 3 o más → 40 puntos, 2 → 25 (una sola no suma);
 *  - asistencia acumulada bajo el mínimo de `config('academico.asistencia_minima')` → 20;
 *  - deuda vencida (misma cola de {@see CobranzaAsistida}): más de 30 días → 35, de 8 a 30 → 25, de 1 a 7 → 15.
 * Nivel: alto desde 60, medio desde 30; por debajo de 30 no se lista. Es una ayuda para priorizar llamadas, no un juicio sobre la
 * persona: cada fila muestra los factores que la explican.
 *
 * No incluye «sin nota» (el modelo de evaluaciones no distingue de forma fiable una nota pendiente de una no exigible todavía).
 *
 * @phpstan-type Fila array{matricula_id: int, estudiante: string, edicion: string, edicion_identificador: string, puntaje: int, nivel: string, factores: list<array{codigo: string, texto: string}>}
 */
class RiesgoDesercion
{
    public const UMBRAL_ALTO = 60;

    public const UMBRAL_MEDIO = 30;

    public function __construct(private readonly GestionAsistencias $asistencias, private readonly CobranzaAsistida $cobranza) {}

    /**
     * @return array{rows: list<Fila>, resumen: array{alto: int, medio: int}}
     */
    public function evaluar(): array
    {
        $matriculas = Matricula::query()
            ->where('estado', '!=', 'anulada')
            ->whereHas('convocatoria', fn (Builder $c) => $c->where('estado', 'en_curso'))
            ->with(['estudiante:id,nombres,apellidos', 'convocatoria:id,identificador,codigo'])
            ->orderBy('id')
            ->get();
        if ($matriculas->isEmpty()) {
            return ['rows' => [], 'resumen' => ['alto' => 0, 'medio' => 0]];
        }

        $faltas = $this->faltasSeguidas(array_values(array_map(static fn (Matricula $m): int => $m->id, $matriculas->all())));
        $porcentajes = $this->porcentajes(array_values(array_unique(array_map(static fn (Matricula $m): int => Entrada::entero($m->convocatoria_id), $matriculas->all()))));
        $mora = [];
        foreach ($this->cobranza->cola()['rows'] as $fila) {
            $mora[$fila['matricula_id']] = max($mora[$fila['matricula_id']] ?? 0, $fila['dias_mora']);
        }

        $minimo = Entrada::entero(config('academico.asistencia_minima', 80));
        $filas = [];
        $resumen = ['alto' => 0, 'medio' => 0];
        foreach ($matriculas as $m) {
            $puntaje = 0;
            $factores = [];
            $seguidas = $faltas[$m->id] ?? 0;
            if ($seguidas >= 3) {
                $puntaje += 40;
                $factores[] = ['codigo' => 'faltas', 'texto' => "{$seguidas} faltas seguidas"];
            } elseif ($seguidas === 2) {
                $puntaje += 25;
                $factores[] = ['codigo' => 'faltas', 'texto' => '2 faltas seguidas'];
            }
            $porcentaje = $porcentajes[$m->id] ?? null;
            if ($porcentaje !== null && $porcentaje < $minimo) {
                $puntaje += 20;
                $factores[] = ['codigo' => 'asistencia', 'texto' => sprintf('Asistencia %.1f%% (mínimo %d%%)', $porcentaje, $minimo)];
            }
            $dias = $mora[$m->id] ?? 0;
            if ($dias > 0) {
                $puntaje += $dias > 30 ? 35 : ($dias > 7 ? 25 : 15);
                $factores[] = ['codigo' => 'deuda', 'texto' => "Deuda vencida hace {$dias} día(s)"];
            }

            $puntaje = min(100, $puntaje);
            if ($puntaje < self::UMBRAL_MEDIO) {
                continue;
            }
            $nivel = $puntaje >= self::UMBRAL_ALTO ? 'alto' : 'medio';
            $resumen[$nivel]++;
            $est = $m->estudiante;
            $filas[] = [
                'matricula_id' => $m->id,
                'estudiante' => $est ? trim($est->nombres.' '.$est->apellidos) : 'Estudiante',
                'edicion' => (string) ($m->convocatoria->codigo ?? ''),
                'edicion_identificador' => (string) ($m->convocatoria->identificador ?? ''),
                'puntaje' => $puntaje,
                'nivel' => $nivel,
                'factores' => $factores,
            ];
        }

        usort($filas, static fn (array $a, array $b): int => [$b['puntaje'], $a['matricula_id']] <=> [$a['puntaje'], $b['matricula_id']]);

        return ['rows' => $filas, 'resumen' => $resumen];
    }

    /**
     * Faltas («ausente») consecutivas más recientes de cada matrícula; una asistencia distinta corta la racha.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function faltasSeguidas(array $ids): array
    {
        $racha = [];
        $cortada = [];
        foreach (array_chunk($ids, 500) as $lote) {
            $filas = DB::table('asistencias as a')
                ->join('sesiones_clase as s', 's.id', '=', 'a.sesion_clase_id')
                ->whereIn('a.matricula_id', $lote)
                ->whereIn('s.estado', ['programada', 'realizada'])
                ->orderBy('a.matricula_id')->orderByDesc('s.fecha')->orderByDesc('s.numero')
                ->get(['a.matricula_id', 'a.estado']);
            foreach ($filas as $f) {
                $id = Entrada::entero($f->matricula_id);
                if ($cortada[$id] ?? false) {
                    continue;
                }
                if (Entrada::texto($f->estado) === 'ausente') {
                    $racha[$id] = ($racha[$id] ?? 0) + 1;
                } else {
                    $cortada[$id] = true;
                }
            }
        }

        return $racha;
    }

    /**
     * Porcentaje de asistencia por matrícula de las ediciones dadas (misma regla del acta de asistencia).
     *
     * @param  list<int>  $convocatoriaIds
     * @return array<int, float|null>
     */
    private function porcentajes(array $convocatoriaIds): array
    {
        $porcentajes = [];
        foreach (Convocatoria::query()->whereIn('id', $convocatoriaIds)->get() as $conv) {
            foreach ($this->asistencias->resumen($conv)['matriculas'] as $fila) {
                $porcentajes[$fila['matricula_id']] = $fila['porcentaje'];
            }
        }

        return $porcentajes;
    }
}
