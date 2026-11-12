<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Models\SolicitudCotizacion;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Bandeja de cotizaciones (lectura). Conserva la restricción comercial vigente: rol `admin` y `cotizaciones.ver`
 * (docs/restriccion-acceso-comercial-admin.md). El listado no incluye datos de contacto ni la necesidad del cliente.
 */
final class CotizacionController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['recibida', 'en_revision'];

    private const ORDENABLES = ['recibida' => 'recibida_at', 'codigo' => 'codigo', 'seguimiento' => 'proximo_seguimiento'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('role:admin'), new Middleware('permission:cotizaciones.ver')];
    }

    public function index(Request $request): JsonResponse
    {
        $query = SolicitudCotizacion::query()
            ->select(['id', 'identificador', 'codigo', 'recibida_at', 'estado', 'responsable_id', 'servicio_nombre_historico', 'proximo_seguimiento'])
            ->with('responsable:id,name');

        $filtros = [
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'codigo' => ListFilter::text($request, 'codigo', 40),
            'desde' => self::fecha($request, 'desde'),
            'hasta' => self::fecha($request, 'hasta'),
            'seguimiento_pendiente' => $request->boolean('seguimiento_pendiente'),
        ];

        if ($filtros['estado'] !== '') {
            $query->where('estado', $filtros['estado']);
        }
        if ($filtros['codigo'] !== '') {
            $query->where('codigo', $filtros['codigo']);
        }
        if ($filtros['desde'] !== '') {
            $query->where('recibida_at', '>=', CarbonImmutable::parse($filtros['desde'], 'America/Lima')->utc());
        }
        if ($filtros['hasta'] !== '') {
            $query->where('recibida_at', '<', CarbonImmutable::parse($filtros['hasta'], 'America/Lima')->addDay()->utc());
        }
        if ($filtros['seguimiento_pendiente']) {
            $query->whereNotNull('proximo_seguimiento')
                ->whereDate('proximo_seguimiento', '<=', CarbonImmutable::now('America/Lima')->toDateString());
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, '-recibida');

        return Listado::responder(Listado::paginar($query, $request), fn (SolicitudCotizacion $solicitud): array => [
            'identificador' => $solicitud->identificador,
            'codigo' => $solicitud->codigo,
            'servicio' => $solicitud->servicio_nombre_historico,
            'estado' => $solicitud->estado,
            'responsable' => $solicitud->responsable?->name,
            'recibida_en' => $solicitud->recibida_at?->toIso8601String(),
            'proximo_seguimiento' => $solicitud->proximo_seguimiento?->toDateString(),
        ], $orden, $filtros);
    }

    /** Fecha AAAA-MM-DD válida o cadena vacía. */
    private static function fecha(Request $request, string $campo): string
    {
        $valor = ListFilter::text($request, $campo, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))
            ? $valor
            : '';
    }
}
