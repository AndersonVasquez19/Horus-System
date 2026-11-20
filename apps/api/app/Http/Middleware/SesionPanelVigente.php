<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SesionPanel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica la vida máxima absoluta de la sesión del panel (config/panel.php), revoca cuentas suspendidas/dadas de baja
 * y exige cambio de contraseña temporal antes de navegar otros módulos. Se ejecuta después de `auth`.
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

        $user = $request->user();
        if (! $user instanceof User || $user->estaDadoDeBaja() || $user->estaSuspendido()) {
            SesionPanel::cerrar($request);

            return response()->json([
                'message' => 'Tu cuenta no se encuentra activa en este momento.',
                'code' => 'cuenta_no_vigente',
            ], 401);
        }

        if ($user->debe_cambiar_password && ! $request->is('api/panel/v1/auth/sesion', 'api/panel/v1/auth/logout', 'api/panel/v1/compartido/perfil')) {
            return response()->json([
                'message' => 'Debes actualizar tu contraseña temporal antes de continuar.',
                'code' => 'cambio_clave_requerido',
            ], 403);
        }

        SesionPanel::marcarSiFalta($request);

        return $next($request);
    }
}
