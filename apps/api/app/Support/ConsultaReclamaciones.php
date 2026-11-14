<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Reclamacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Filtros de la bandeja del Libro de Reclamaciones. Los usan el Blade (`Admin\ReclamacionController`) y el panel
 * (`Api\Panel\Compartido\ReclamacionController`), así que no pueden divergir: mismas reglas de validación, búsqueda
 * sin comodines y rango en días completos de Lima.
 */
final class ConsultaReclamaciones
{
    public const FILTROS = ['busqueda', 'tipo', 'estado', 'desde', 'hasta'];

    /**
     * Valida y devuelve los filtros (422 si alguno no es válido).
     *
     * @return array{busqueda?: string|null, tipo?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null, page?: int|null}
     */
    public static function validar(Request $request): array
    {
        /** @var array{busqueda?: string|null, tipo?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null, page?: int|null} $filtros */
        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', Rule::in(['queja', 'reclamo'])],
            'estado' => ['nullable', 'string', Rule::in(['abierto', 'respondido', 'concluido_por_acuerdo'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $filtros;
    }

    /**
     * @param  Builder<Reclamacion>  $consulta
     * @param  array<string, mixed>  $filtros
     */
    public static function aplicar(Builder $consulta, array $filtros): void
    {
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        if ($busqueda !== '') {
            $termino = '%'.strtr($busqueda, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            // Escape explícito compartido: SQLite no define el escape implícito de PostgreSQL.
            // Operador y columnas son SQL fijo; el texto personal siempre viaja como parámetro.
            $operador = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $consulta->where(function ($q) use ($termino, $operador) {
                $q->whereRaw("numero {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("nombres {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("apellidos {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("(nombres || ' ' || apellidos) {$operador} ? ESCAPE '!'", [$termino]);
            });
        }

        if (! empty($filtros['tipo'])) {
            $consulta->where('tipo', $filtros['tipo']);
        }

        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }

        if (! empty($filtros['desde'])) {
            $consulta->where('recibida_at', '>=', CarbonImmutable::parse((string) $filtros['desde'], 'America/Lima')->startOfDay()->utc());
        }

        if (! empty($filtros['hasta'])) {
            $consulta->where('recibida_at', '<=', CarbonImmutable::parse((string) $filtros['hasta'], 'America/Lima')->endOfDay()->utc());
        }
    }

    /**
     * Nombres (nunca valores) de los filtros usados, para la constancia de acceso a la bandeja.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<string>
     */
    public static function usados(array $filtros): array
    {
        return array_values(array_filter(
            self::FILTROS,
            fn (string $filtro): bool => trim((string) ($filtros[$filtro] ?? '')) !== '',
        ));
    }

    /**
     * Totales de la bandeja completa (no del filtro).
     *
     * @return array{total: int, quejas: int, reclamos: int, abiertos: int}
     */
    public static function metricas(): array
    {
        $metricas = Reclamacion::query()
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN tipo = 'queja' THEN 1 END) as total_quejas,
                COUNT(CASE WHEN tipo = 'reclamo' THEN 1 END) as total_reclamos,
                COUNT(CASE WHEN estado = 'abierto' THEN 1 END) as total_abiertos
            ")
            ->first();

        return [
            'total' => (int) ($metricas?->getAttribute('total') ?? 0),
            'quejas' => (int) ($metricas?->getAttribute('total_quejas') ?? 0),
            'reclamos' => (int) ($metricas?->getAttribute('total_reclamos') ?? 0),
            'abiertos' => (int) ($metricas?->getAttribute('total_abiertos') ?? 0),
        ];
    }
}
