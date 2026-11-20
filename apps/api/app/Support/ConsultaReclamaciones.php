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
    public const FILTROS = ['busqueda', 'tipo', 'estado', 'desde', 'hasta', 'canal', 'responsable', 'plazo'];

    /**
     * Valida y devuelve los filtros (422 si alguno no es válido).
     *
     * @return array<string, mixed>
     */
    public static function validar(Request $request): array
    {
        /** @var array<string, mixed> $filtros */
        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', Rule::in(['queja', 'reclamo'])],
            'estado' => ['nullable', 'string', Rule::in(['abierto', 'respondido', 'concluido_por_acuerdo'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'canal' => ['nullable', 'string', Rule::in(['web', 'respaldo'])],
            'responsable' => ['nullable', 'string', 'regex:/\A(sin_asignar|mio|[1-9][0-9]{0,17})\z/'],
            'plazo' => ['nullable', 'string', Rule::in(['vencidas', 'vencen_hoy', 'por_vencer'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $filtros;
    }

    /**
     * @param  Builder<Reclamacion>  $consulta
     * @param  array<string, mixed>  $filtros
     */
    public static function aplicar(Builder $consulta, array $filtros, ?int $usuarioId = null): void
    {
        $busqueda = trim(Entrada::texto(($filtros['busqueda'] ?? '')));
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
            $consulta->where('recibida_at', '>=', CarbonImmutable::parse(Entrada::texto($filtros['desde']), 'America/Lima')->startOfDay()->utc());
        }

        if (! empty($filtros['hasta'])) {
            $consulta->where('recibida_at', '<=', CarbonImmutable::parse(Entrada::texto($filtros['hasta']), 'America/Lima')->endOfDay()->utc());
        }

        if (! empty($filtros['canal'])) {
            $consulta->where('canal', $filtros['canal']);
        }

        if (! empty($filtros['responsable'])) {
            if ($filtros['responsable'] === 'sin_asignar') {
                $consulta->whereNull('responsable_id');
            } elseif ($filtros['responsable'] === 'mio') {
                if ($usuarioId !== null) {
                    $consulta->where('responsable_id', $usuarioId);
                }
            } else {
                $consulta->where('responsable_id', Entrada::entero($filtros['responsable']));
            }
        }

        if (! empty($filtros['plazo'])) {
            $hoy = HoraLocal::ahora()->startOfDay();
            $hoyTexto = $hoy->toDateString();
            $limiteAviso = DiasHabiles::sumar($hoy, Entrada::entero(config('reclamaciones.aviso_dias_habiles', 3)))->toDateString();
            $consulta->where('estado', 'abierto')->whereNull('suspension_desde');
            match ($filtros['plazo']) {
                'vencidas' => $consulta->whereDate('fecha_limite', '<', $hoyTexto),
                'vencen_hoy' => $consulta->whereDate('fecha_limite', '=', $hoyTexto),
                'por_vencer' => $consulta->whereDate('fecha_limite', '>', $hoyTexto)->whereDate('fecha_limite', '<=', $limiteAviso),
                default => null,
            };
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
            fn (string $filtro): bool => trim(Entrada::texto(($filtros[$filtro] ?? ''))) !== '',
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
            'total' => Entrada::entero(($metricas?->getAttribute('total') ?? 0)),
            'quejas' => Entrada::entero(($metricas?->getAttribute('total_quejas') ?? 0)),
            'reclamos' => Entrada::entero(($metricas?->getAttribute('total_reclamos') ?? 0)),
            'abiertos' => Entrada::entero(($metricas?->getAttribute('total_abiertos') ?? 0)),
        ];
    }

    /**
     * Conteo de reclamaciones abiertas sin plazo suspendido según su cercanía a la fecha límite.
     *
     * @return array{vencidas: int, vencen_hoy: int, por_vencer: int}
     */
    public static function plazos(): array
    {
        $hoy = HoraLocal::ahora()->startOfDay();
        $hoyTexto = $hoy->toDateString();
        $limiteAviso = DiasHabiles::sumar($hoy, Entrada::entero(config('reclamaciones.aviso_dias_habiles', 3)))->toDateString();

        $fila = Reclamacion::query()
            ->where('estado', 'abierto')
            ->whereNull('suspension_desde')
            ->whereDate('fecha_limite', '<=', $limiteAviso)
            ->selectRaw(
                'COUNT(CASE WHEN fecha_limite < ? THEN 1 END) as vencidas, COUNT(CASE WHEN fecha_limite = ? THEN 1 END) as vencen_hoy, COUNT(CASE WHEN fecha_limite > ? AND fecha_limite <= ? THEN 1 END) as por_vencer',
                [$hoyTexto, $hoyTexto, $hoyTexto, $limiteAviso],
            )
            ->first();

        return [
            'vencidas' => Entrada::entero(($fila?->getAttribute('vencidas') ?? 0)),
            'vencen_hoy' => Entrada::entero(($fila?->getAttribute('vencen_hoy') ?? 0)),
            'por_vencer' => Entrada::entero(($fila?->getAttribute('por_vencer') ?? 0)),
        ];
    }
}
