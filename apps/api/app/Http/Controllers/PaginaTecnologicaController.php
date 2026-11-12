<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ContenidoPublicoServicios;
use App\Support\PaginasServicios;
use Illuminate\Http\Response;

/**
 * Cableado estructurado y videovigilancia: contenido editorial si está autorizado; si no, el HTML estático original.
 */
class PaginaTecnologicaController extends Controller
{
    public function cableado(ContenidoPublicoServicios $contenido): Response
    {
        return $this->pagina('pages.tecnologias.cableado-estructurado', $contenido->pagina(PaginasServicios::CABLEADO));
    }

    public function camaras(ContenidoPublicoServicios $contenido): Response
    {
        return $this->pagina('pages.tecnologias.camaras-seguridad', $contenido->pagina(PaginasServicios::VIDEOVIGILANCIA));
    }

    /** @param array<string, mixed>|null $contenidoServicio */
    private function pagina(string $vista, ?array $contenidoServicio): Response
    {
        return response()->view($vista, ['contenidoServicio' => $contenidoServicio])->header('Cache-Control', 'no-store, private');
    }
}
