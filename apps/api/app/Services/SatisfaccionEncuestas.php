<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\EncuestaCurso;
use App\Models\Matricula;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan de mejora del panel, Fase 3.11: encuesta de satisfacción al cerrar el acta y KPI.
 *
 * Al cerrar el acta se emite (una por matrícula no anulada, idempotente) la encuesta que ya existía; así la tasa de respuesta
 * tiene denominador. La calificación va de 1 (triste) a 3 (feliz). El KPI no expone texto libre ni datos personales.
 */
final class SatisfaccionEncuestas
{
    /** Ventana del KPI del tablero, en días. */
    public const DIAS_KPI = 90;

    /** Emite las encuestas que falten de una edición; devuelve cuántas creó. */
    public function emitirParaEdicion(Convocatoria $convocatoria): int
    {
        $existentes = EncuestaCurso::query()->where('convocatoria_id', $convocatoria->id)->pluck('matricula_id')->all();
        $faltan = Matricula::query()->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'anulada')
            ->whereNotIn('id', $existentes)->orderBy('id')->pluck('id');

        $ahora = now();
        foreach ($faltan->chunk(200) as $lote) {
            DB::table('encuestas_curso')->insert($lote->map(fn ($id): array => [
                'identificador' => (string) Str::uuid(), 'matricula_id' => $id, 'convocatoria_id' => $convocatoria->id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ])->all());
        }

        return $faltan->count();
    }

    /**
     * @return array{emitidas: int, respondidas: int, tasa_respuesta: int|null, promedio: float|null, felices_pct: int|null}
     */
    public function resumen(?int $convocatoriaId = null, ?int $dias = null): array
    {
        $consulta = DB::table('encuestas_curso');
        if ($convocatoriaId !== null) {
            $consulta->where('convocatoria_id', $convocatoriaId);
        }
        if ($dias !== null) {
            $consulta->where('created_at', '>=', HoraLocal::ahora()->subDays($dias)->utc());
        }
        $fila = $consulta->selectRaw(
            'COUNT(*) as emitidas, COUNT(respondida_at) as respondidas, '
            .'AVG(CASE WHEN respondida_at IS NOT NULL THEN calificacion END) as promedio, '
            .'COUNT(CASE WHEN respondida_at IS NOT NULL AND calificacion = 3 THEN 1 END) as felices',
        )->first();

        $emitidas = Entrada::entero($fila->emitidas ?? 0);
        $respondidas = Entrada::entero($fila->respondidas ?? 0);

        return [
            'emitidas' => $emitidas,
            'respondidas' => $respondidas,
            'tasa_respuesta' => $emitidas > 0 ? (int) round($respondidas * 100 / $emitidas) : null,
            'promedio' => $respondidas > 0 ? round(Entrada::decimal($fila->promedio ?? 0), 2) : null,
            'felices_pct' => $respondidas > 0 ? (int) round(Entrada::entero($fila->felices ?? 0) * 100 / $respondidas) : null,
        ];
    }
}
