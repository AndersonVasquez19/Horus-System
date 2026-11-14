<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Páginas públicas estáticas que ya sirve el frontend Next.js (apps/web). Conservan su ruta con nombre para que
 * los enlaces de las vistas Blade que quedan (barra, pie, panel) sigan resolviendo; la respuesta es una
 * redirección temporal (302) a `HORUS_WEB_URL`. Sin esa URL válida (http/https) responde 404.
 */
final class RedirigirAWebController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $base = (string) config('horus-web.url');
        $destino = (string) $request->route('destino');

        // Un destino puede llevar parámetros de la ruta (`/admin/academico/estudiantes/{estudiante}`): solo se
        // sustituyen valores alfanuméricos y se codifican; cualquier otro valor responde 404.
        $valido = true;
        $destino = (string) preg_replace_callback('/\{(\w+)\}/', function (array $coincidencia) use ($request, &$valido): string {
            $valor = $request->route($coincidencia[1]);
            if (! is_scalar($valor) || preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $valor) !== 1) {
                $valido = false;

                return '';
            }

            return rawurlencode((string) $valor);
        }, $destino);

        abort_unless($valido && preg_match('#^https?://[^\s/]+#i', $base) === 1 && str_starts_with($destino, '/'), 404);

        return redirect()->away($base.$destino);
    }
}
