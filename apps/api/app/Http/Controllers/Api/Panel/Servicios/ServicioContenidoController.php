<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarServicioElementoRequest;
use App\Http\Requests\SubirImagenPrincipalRequest;
use App\Models\ServicioTecnologico;
use App\Services\GestionServiciosTecnologicos;
use App\Services\ImagenesServicios;
use App\Support\Panel\DetalleServicio;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Imagen principal y tarjetas de la ficha pública de un servicio (multipart). No duplica reglas: usa los mismos Form
 * Requests, {@see ImagenesServicios} (disco privado de Laravel, nombre generado por el servidor, tipo real por
 * contenido) y {@see GestionServiciosTecnologicos::contenido()} (bloqueo, permiso, revisión `If-Match`, ficha no
 * publicada, auditoría, limpieza de archivos huérfanos). La revisión llega en `_revision`.
 *
 * El navegador nunca recibe rutas de almacenamiento: la vista previa se sirve por las rutas autenticadas `imagen`.
 */
final class ServicioContenidoController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionServiciosTecnologicos $gestion, private readonly ImagenesServicios $imagenes) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:servicios.ver', only: ['verImagenPrincipal', 'verImagenElemento']),
            new Middleware('permission:servicios.editar', only: ['imagenPrincipal', 'quitarImagenPrincipal', 'elementoStore', 'elementoUpdate', 'elementoDestroy']),
        ];
    }

    public function imagenPrincipal(SubirImagenPrincipalRequest $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->safe()->only(['imagen_principal_alt']);

        return DetalleServicio::responder(
            $this->gestion->contenido($request, $servicio, 'imagen_principal', $datos, $request->file('imagen')),
            200,
            'La imagen principal se guardó.',
        );
    }

    public function quitarImagenPrincipal(Request $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return DetalleServicio::responder($this->gestion->contenido($request, $servicio, 'imagen_principal'), 200, 'La imagen principal se quitó.');
    }

    public function elementoStore(GuardarServicioElementoRequest $request, int $servicio): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return DetalleServicio::responder(
            $this->gestion->contenido($request, $servicio, 'elemento_crear', $request->safe()->except(['imagen']), $request->file('imagen')),
            201,
            'La tarjeta se agregó.',
        );
    }

    public function elementoUpdate(GuardarServicioElementoRequest $request, int $servicio, int $elemento): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return DetalleServicio::responder(
            $this->gestion->contenido($request, $servicio, 'elemento_editar', $request->safe()->except(['imagen']), $request->file('imagen'), $elemento),
            200,
            'La tarjeta se actualizó.',
        );
    }

    public function elementoDestroy(Request $request, int $servicio, int $elemento): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);

        return DetalleServicio::responder($this->gestion->contenido($request, $servicio, 'elemento_eliminar', [], null, $elemento), 200, 'La tarjeta se eliminó.');
    }

    public function verImagenPrincipal(int $servicio): Response
    {
        $ficha = ServicioTecnologico::query()->findOrFail($servicio)->publicacion;

        return $this->archivo($ficha?->imagen_principal_ruta);
    }

    public function verImagenElemento(int $servicio, int $elemento): Response
    {
        $ficha = ServicioTecnologico::query()->findOrFail($servicio)->publicacion;
        $tarjeta = $ficha?->elementos()->whereKey($elemento)->first();

        return $this->archivo($tarjeta?->imagen_ruta);
    }

    private function archivo(?string $ruta): Response
    {
        $imagen = $this->imagenes->contenido($ruta);
        abort_if($imagen === null, 404);

        return response($imagen['bytes'], 200, [
            'Content-Type' => $imagen['tipo'],
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
