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

        abort_unless(preg_match('#^https?://[^\s/]+#i', $base) === 1 && str_starts_with($destino, '/'), 404);

        return redirect()->away($base.$destino);
    }
}
