<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Filtros del listado y de la exportación de la auditoría. Los comparten el Blade y la API del panel para que no
 * diverjan: los registros se guardan en UTC y el personal trabaja en Lima, así que los días son completos en Lima.
 */
final class ConsultaAuditoria
{
    /**
     * Una fecha con formato distinto de AAAA-MM-DD (o inexistente, como 2026-02-30) se ignora igual que un filtro vacío.
     *
     * @return array{accion: string, modelo: string, usuario: string, desde: string, hasta: string}
     */
    public static function filtros(Request $request): array
    {
        return [
            'accion' => ListFilter::text($request, 'accion'),
            'modelo' => ListFilter::text($request, 'modelo'),
            'usuario' => ListFilter::text($request, 'usuario'),
            'desde' => self::fecha(ListFilter::text($request, 'desde')),
            'hasta' => self::fecha(ListFilter::text($request, 'hasta')),
        ];
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array{accion: string, modelo: string, usuario: string, desde: string, hasta: string}  $filtros
     */
    public static function aplicar(Builder $query, array $filtros): void
    {
        BusquedaLike::contiene($query, ['action'], $filtros['accion']);
        BusquedaLike::contiene($query, ['model_type'], $filtros['modelo']);

        if ($filtros['usuario'] !== '') {
            $usuario = $filtros['usuario'];
            $query->whereHas('user', function (Builder $q) use ($usuario): void {
                BusquedaLike::contiene($q, ['name', 'email'], $usuario);
            });
        }

        // Días completos en hora de Lima convertidos al UTC en que se guarda `created_at`.
        if ($filtros['desde'] !== '') {
            $query->where('created_at', '>=', Carbon::parse($filtros['desde'], HoraLocal::ZONA)->startOfDay()->utc());
        }

        if ($filtros['hasta'] !== '') {
            $query->where('created_at', '<=', Carbon::parse($filtros['hasta'], HoraLocal::ZONA)->endOfDay()->utc());
        }
    }

    private static function fecha(string $valor): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $valor) !== 1) {
            return '';
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $valor));

        return checkdate($mes, $dia, $anio) ? $valor : '';
    }
}
