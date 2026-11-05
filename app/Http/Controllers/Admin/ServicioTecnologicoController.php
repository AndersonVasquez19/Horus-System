<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutorizarServicioPublicacionRequest;
use App\Http\Requests\GuardarServicioElementoRequest;
use App\Http\Requests\GuardarServicioRequest;
use App\Http\Requests\PrepararServicioPublicacionRequest;
use App\Http\Requests\SubirImagenPrincipalRequest;
use App\Models\ServicioTecnologico;
use App\Services\GestionServiciosTecnologicos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class ServicioTecnologicoController extends Controller implements HasMiddleware
{
    public function __construct(private GestionServiciosTecnologicos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:servicios.ver', only: ['index', 'show', 'publicacion']),
            new Middleware('permission:servicios.crear', only: ['store']),
            new Middleware('permission:servicios.editar', only: ['update', 'estado', 'preparar', 'imagenPrincipal', 'elementoStore', 'elementoUpdate', 'elementoDestroy']),
            new Middleware('permission:servicios.publicar', only: ['autorizar', 'retirar']),
        ];
    }

    public function index(Request $request): JsonResponse|Response
    {
        $datos = $request->validate([
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo', 'archivado'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $consulta = ServicioTecnologico::query();
        if (isset($datos['estado'])) {
            $consulta->where('estado', $datos['estado']);
        }

        $servicios = $consulta->orderBy('id')->paginate((int) ($datos['por_pagina'] ?? 15));
        if (! $request->expectsJson()) {
            $servicios->withQueryString();
            $servicios->getCollection()->load('publicacion');

            return response()->view('admin.servicios.index', ['servicios' => $servicios, 'estado' => $datos['estado'] ?? ''])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json($servicios)
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarServicioRequest $request): JsonResponse
    {
        return $this->respuesta($this->gestion->crear($request, $request->validated()), 201);
    }

    public function show(ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($servicio);
    }

    public function publicacion(ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($servicio);
    }

    public function update(GuardarServicioRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->cambiar($request, $servicio->id, 'editar', $request->validated()));
    }

    public function estado(GuardarServicioRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->cambiar($request, $servicio->id, 'estado', $request->validated()));
    }

    public function preparar(PrepararServicioPublicacionRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->cambiar($request, $servicio->id, 'preparar', $request->validated()));
    }

    public function imagenPrincipal(SubirImagenPrincipalRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        $datos = $request->safe()->only(['imagen_principal_alt']);

        return $this->respuesta($this->gestion->contenido($request, $servicio->id, 'imagen_principal', $datos, $request->file('imagen')));
    }

    public function elementoStore(GuardarServicioElementoRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->contenido($request, $servicio->id, 'elemento_crear', $request->safe()->except('imagen'), $request->file('imagen')), 201);
    }

    public function elementoUpdate(GuardarServicioElementoRequest $request, ServicioTecnologico $servicio, int $elemento): JsonResponse
    {
        return $this->respuesta($this->gestion->contenido($request, $servicio->id, 'elemento_editar', $request->safe()->except('imagen'), $request->file('imagen'), $elemento));
    }

    public function elementoDestroy(Request $request, ServicioTecnologico $servicio, int $elemento): JsonResponse
    {
        return $this->respuesta($this->gestion->contenido($request, $servicio->id, 'elemento_eliminar', [], null, $elemento));
    }

    public function autorizar(AutorizarServicioPublicacionRequest $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->cambiar($request, $servicio->id, 'publicar'));
    }

    public function retirar(Request $request, ServicioTecnologico $servicio): JsonResponse
    {
        return $this->respuesta($this->gestion->cambiar($request, $servicio->id, 'retirar'));
    }

    private function respuesta(ServicioTecnologico $servicio, int $status = 200): JsonResponse
    {
        $publicacion = $servicio->publicacion()->first();
        $publicacion?->load('elementos');
        $estado = $publicacion->estado ?? 'sin_ficha';
        if ($estado === 'publicada') {
            $hoy = now('America/Lima')->toDateString();
            $estado = $publicacion->vigente_hasta->toDateString() < $hoy ? 'caducada'
                : ($publicacion->vigente_desde->toDateString() > $hoy ? 'programada' : 'publicada');
        }

        return response()->json(['servicio' => $servicio, 'publicacion' => $publicacion, 'estado_editorial' => $estado], $status)
            ->header('ETag', $servicio->etag())->header('Cache-Control', 'no-store, private');
    }
}
