<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SesionPanel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica la vida máxima absoluta de la sesión del panel (config/panel.php). Se ejecuta después de `auth`.
 *
 * Una sesión sin marca de inicio (por ejemplo, abierta en el panel Blade) no tiene vida absoluta conocida: se
 * marca en ese momento, de modo que nunca supera el límite contado desde su primera petición al panel.
 */
final class SesionPanelVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SesionPanel::expirada($request)) {
            SesionPanel::cerrar($request);

            return response()->json([
                'message' => 'La sesión expiró. Vuelve a iniciar sesión.',
                'code' => 'sesion_expirada',
            ], 401);
        }

        SesionPanel::marcarSiFalta($request);

        return $next($request);
    }
}
