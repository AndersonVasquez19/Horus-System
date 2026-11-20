<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Entrada;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege /api/public/v1/*: solo el BFF de apps/web (servidor a servidor) las consume. Sin token configurado
 * (o de menos de 32 caracteres) las rutas no existen (404); con token incorrecto responden 401. Comparación en
 * tiempo constante. El token nunca se registra ni se devuelve.
 */
final class AutenticarServicioWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = Entrada::texto(config('horus-web.service_token'));
        abort_if(strlen($esperado) < 32, 404);

        $recibido = $request->header('X-Horus-Web-Token');
        abort_unless(is_string($recibido) && hash_equals($esperado, $recibido), 401);

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
