<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Models\ServicioTecnologico;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Catálogo interno de servicios tecnológicos (lectura). Mismo permiso que el panel Blade (`servicios.ver`).
 */
final class ServicioController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['activo', 'inactivo', 'archivado'];

    public const ESTADOS_PUBLICACION = ['sin_ficha', 'borrador', 'publicada', 'retirada'];

    private const ORDENABLES = ['nombre' => 'nombre', 'codigo' => 'codigo', 'actualizado' => 'updated_at'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:servicios.ver')];
    }

    public function index(Request $request): JsonResponse
    {
        $query = ServicioTecnologico::query()->with('publicacion:id,servicio_tecnologico_id,estado,vigente_desde,vigente_hasta');

        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'publicacion' => ListFilter::in($request, 'publicacion', self::ESTADOS_PUBLICACION),
        ];

        BusquedaLike::contiene($query, ['codigo', 'nombre', 'categoria'], $filtros['busqueda']);
        if ($filtros['estado'] !== '') {
            $query->where('estado', $filtros['estado']);
        }
        if ($filtros['publicacion'] === 'sin_ficha') {
            $query->doesntHave('publicacion');
        } elseif ($filtros['publicacion'] !== '') {
            $query->whereHas('publicacion', fn ($consulta) => $consulta->where('estado', $filtros['publicacion']));
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, 'nombre');

        return Listado::responder(Listado::paginar($query, $request), fn (ServicioTecnologico $servicio): array => [
            'id' => $servicio->id,
            'codigo' => $servicio->codigo,
            'nombre' => $servicio->nombre,
            'categoria' => $servicio->categoria,
            'estado' => $servicio->estado,
            'publicacion' => $servicio->publicacion === null ? null : [
                'estado' => $servicio->publicacion->estado,
                'vigente_desde' => substr((string) $servicio->publicacion->vigente_desde, 0, 10) ?: null,
                'vigente_hasta' => substr((string) $servicio->publicacion->vigente_hasta, 0, 10) ?: null,
            ],
            'actualizado_en' => $servicio->updated_at?->toIso8601String(),
        ], $orden, $filtros);
    }
}
