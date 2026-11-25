<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatLog;
use App\Support\Entrada;
use App\Support\IntencionesAsistente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Cifras del asistente para el panel, calculadas sobre el registro del chat (nunca sobre texto del visitante).
 * Las pruebas del probador (`canal = admin`) no cuentan. Funciona antes de aplicar la migración de métricas: en ese
 * caso no separa canales y los votos salen como no disponibles.
 */
final class MetricasAsistente
{
    public const DIAS = 30;

    /**
     * @return array{dias: int, consultas: int, conversaciones: int, respondidas: int, contingencia: int,
     *     sin_dato: int, intenciones: list<array{nombre: string, total: int}>,
     *     votos: array{utiles: int, no_utiles: int}|null, separa_canal: bool}
     */
    public function resumen(): array
    {
        $tabla = (new ChatLog)->getTable();
        $conCanal = Schema::hasColumn($tabla, 'canal');
        $conVoto = Schema::hasColumn($tabla, 'voto');
        $base = static fn (): Builder => ChatLog::query()
            ->where('created_at', '>=', now()->subDays(self::DIAS))
            ->when($conCanal, fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereNull('canal')->orWhere('canal', 'web')));

        $intenciones = $base()->whereNotNull('intent_detectado')->where('intent_detectado', '!=', '')
            ->toBase()->selectRaw('intent_detectado as nombre, count(*) as total')
            ->groupBy('intent_detectado')->orderByDesc('total')->orderBy('intent_detectado')->limit(8)->get()
            ->map(fn (object $fila): array => [
                'nombre' => Entrada::texto(((array) $fila)['nombre'] ?? ''),
                'total' => Entrada::entero(((array) $fila)['total'] ?? 0),
            ])->values()->all();

        return [
            'dias' => self::DIAS,
            'consultas' => $base()->count(),
            'conversaciones' => $base()->distinct()->count('session_id'),
            'respondidas' => $base()->where('fuente', 'advisor')->count(),
            'contingencia' => $base()->where('fuente', 'respaldo')->count(),
            'sin_dato' => $base()->whereIn('intent_detectado', IntencionesAsistente::SIN_DATO)->count(),
            'intenciones' => array_values($intenciones),
            'votos' => $conVoto ? [
                'utiles' => $base()->where('voto', 'up')->count(),
                'no_utiles' => $base()->where('voto', 'down')->count(),
            ] : null,
            'separa_canal' => $conCanal,
        ];
    }
}
