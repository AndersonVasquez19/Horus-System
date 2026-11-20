<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Reclamaciones;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compuerta de la recepción pública del Libro de Reclamaciones. Aún no está conectada a ninguna ruta
 * (la ruta pública es la Fase 3). Apagado, no se lee ni valida el cuerpo.
 */
class RecepcionReclamacionesHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Reclamaciones::habilitado(), 404);

        return $next($request);
    }
}
