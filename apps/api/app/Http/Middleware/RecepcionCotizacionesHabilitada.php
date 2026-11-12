<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecepcionCotizacionesHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        // Comparación estricta: solo el booleano true habilita. Apagado, no se lee ni valida el cuerpo.
        abort_unless(config('recepcion-cotizaciones.enabled') === true, 404);

        return $next($request);
    }
}
