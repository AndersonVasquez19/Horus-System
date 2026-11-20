<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Entrada;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de /api/panel/v1/*: solo el BFF de apps/web (servidor a servidor) puede llamarla.
 *
 * - Sin token de servicio configurado (o de menos de 32 caracteres) las rutas no existen (404); con token
 *   incorrecto responden 401. Comparación en tiempo constante; el token nunca se registra ni se devuelve.
 * - Fuerza respuestas JSON: ninguna excepción del panel termina en una redirección o en una vista.
 * - Solo tras autenticar al BFF se acepta su `X-Horus-Client-Ip` como IP del cliente. Así los limitadores
 *   por IP (login, recuperación) cuentan por visitante y no por el servidor de Next. Un valor que no sea una IP
 *   válida se ignora.
 * - Las respuestas no se guardan en cachés.
 */
final class AutenticarBffPanel
{
    public const CABECERA_IP = 'X-Horus-Client-Ip';

    public function handle(Request $request, Closure $next): Response
    {
        $esperado = Entrada::texto(config('horus-web.service_token'));
        abort_if(strlen($esperado) < 32, 404);

        $recibido = $request->header('X-Horus-Web-Token');
        if (! is_string($recibido) || ! hash_equals($esperado, $recibido)) {
            return response()->json(['message' => 'No autorizado.', 'code' => 'servicio_no_autorizado'], 401);
        }

        $request->headers->set('Accept', 'application/json');

        $ip = $request->header(self::CABECERA_IP);
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
