<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutorizarServicioPublicacionRequest;
use App\Http\Requests\GuardarServicioRequest;
use App\Http\Requests\PrepararServicioPublicacionRequest;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Services\GestionServiciosTecnologicos;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Catálogo interno de servicios tecnológicos. Mismos permisos, Form Requests y servicio
 * ({@see GestionServiciosTecnologicos}: bloqueo, revisión, auditoría) que el módulo Blade. La revisión llega en
 * `_revision` ({@see PrecondicionesPanel}). La imagen principal y las tarjetas de la ficha (multipart) siguen en el
 * módulo Blade: el BFF solo reenvía JSON.
 */
final class ServicioController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['activo', 'inactivo', 'archivado'];

    public const ESTADOS_PUBLICACION = ['sin_ficha', 'borrador', 'publicada', 'retirada'];

    private const ORDENABLES = ['nombre' => 'nombre', 'codigo' => 'codigo', 'actualizado' => 'updated_at'];

    public function __construct(private readonly GestionServiciosTecnologicos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:servicios.ver', only: ['index', 'show']),
            new Middleware('permission:servicios.crear', only: ['store']),
            new Middleware('permission:servicios.editar', only: ['update', 'estado', 'preparar']),
            new Middleware('permission:servicios.publicar', only: ['autorizar', 'retirar']),
        ];
    }

    public function show(int $servicio): JsonResponse
    {
        return $this->detalle(ServicioTecnologico::query()->findOrFail($servicio));
    }

    public function store(GuardarServicioRequest $request): JsonResponse
    {
        $servicio = $this->gestion->crear($request, $request->validated());

        return $this->detalle($servicio, 201, 'El servicio se registró correctamente.');
    }

    public function update(GuardarServicioRequest $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return $this->detalle($this->gestion->cambiar($request, $servicio, 'editar', $request->validated()), 200, 'Los datos del servicio se actualizaron.');
    }

    public function estado(Request $request, int $servicio): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', Rule::in(self::ESTADOS)]]);
        PrecondicionesPanel::aplicar($request);

        return $this->detalle($this->gestion->cambiar($request, $servicio, 'estado', $datos), 200, 'El estado del servicio se actualizó.');
    }

    public function preparar(PrepararServicioPublicacionRequest $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return $this->detalle($this->gestion->cambiar($request, $servicio, 'preparar', $request->validated()), 200, 'El borrador de la ficha se guardó.');
    }

    public function autorizar(AutorizarServicioPublicacionRequest $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return $this->detalle($this->gestion->cambiar($request, $servicio, 'publicar'), 200, 'La ficha pública fue autorizada. Su visibilidad depende de la vigencia y del estado del servicio.');
    }

    public function retirar(Request $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return $this->detalle($this->gestion->cambiar($request, $servicio, 'retirar'), 200, 'La ficha dejó de estar publicada.');
    }

    private function detalle(ServicioTecnologico $servicio, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $servicio = ServicioTecnologico::query()->findOrFail($servicio->id);
        $ficha = $servicio->publicacion;
        $editorial = $ficha === null ? 'sin_ficha' : $ficha->estado;
        if ($editorial === 'publicada') {
            $hoy = now('America/Lima')->toDateString();
            $editorial = $ficha->vigente_hasta->toDateString() < $hoy ? 'caducada'
                : ($ficha->vigente_desde->toDateString() > $hoy ? 'programada' : 'publicada');
        }

        $datos = [
            'id' => $servicio->id,
            'codigo' => $servicio->codigo,
            'nombre' => $servicio->nombre,
            'categoria' => $servicio->categoria,
            'estado' => $servicio->estado,
            'identificador_publico' => $servicio->identificador_publico,
            'revision' => $servicio->etag(),
            'estado_editorial' => $editorial,
            'actualizado_en' => $servicio->updated_at?->toIso8601String(),
            'ficha' => $ficha === null ? null : $this->ficha($ficha),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $servicio->etag());
    }

    /** @return array<string, mixed> */
    private function ficha(ServicioPublicacion $ficha): array
    {
        return [
            'estado' => $ficha->estado,
            'titulo' => $ficha->titulo,
            'resumen' => $ficha->resumen,
            'descripcion' => $ficha->descripcion,
            'categoria' => $ficha->categoria,
            'vigente_desde' => $ficha->vigente_desde->toDateString(),
            'vigente_hasta' => $ficha->vigente_hasta->toDateString(),
            'titulo_tarjetas' => $ficha->titulo_tarjetas,
            'imagen_principal_alt' => $ficha->imagen_principal_alt,
            'con_imagen' => $ficha->imagen_principal_ruta !== null,
            'tarjetas' => $ficha->elementos()->count(),
            'autorizado_en' => $ficha->autorizado_at?->toIso8601String(),
        ];
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
