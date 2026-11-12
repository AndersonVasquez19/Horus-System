<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evita que las pantallas y respuestas JSON del panel administrativo (con datos personales,
 * académicos o de auditoría) queden almacenadas en la caché del navegador o de proxies intermedios.
 * Las descargas de archivos conservan sus propias cabeceras de entrega.
 */
class NoStoreAdminResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if (str_contains(strtolower($disposition), 'attachment')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
