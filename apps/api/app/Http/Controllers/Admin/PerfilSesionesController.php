<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\VerificacionRegistroService;
use App\Support\SesionesActivas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesiones activas del propio usuario del panel. Igual que el perfil, no requiere un permiso de Spatie: el
 * usuario se toma siempre de la sesión y solo se actúa sobre sus filas. Cerrar sesiones exige la contraseña
 * actual, para que una sesión desatendida no baste para expulsar al titular de sus otros dispositivos.
 */
class PerfilSesionesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'disponible' => SesionesActivas::disponible(),
            'sesiones' => SesionesActivas::listar($user, $request->session()->getId()),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Cierra todas las demás sesiones web y revoca los JWT emitidos (todas las APIs del usuario). */
    public function destroyOtras(Request $request): RedirectResponse|JsonResponse
    {
        $this->validarClave($request);

        /** @var User $actual */
        $actual = $request->user();
        $sesionActual = $request->session()->getId();

        $cerradas = DB::transaction(function () use ($actual, $sesionActual, $request): int {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();

            // Revoca los JWT y cualquier token "recordarme"; esta sesión web se conserva.
            $user->forceFill(['jwt_version' => (int) $user->jwt_version + 1])->setRememberToken(Str::random(60));
            $user->save();

            $cerradas = SesionesActivas::cerrarOtras($user, $sesionActual);

            // Con la verificación de registro activa la sesión se compara con `jwt_version`.
            if (VerificacionRegistroService::enabled() && $user->hasVerifiedEmail()) {
                $request->session()->put('auth.verification_version', (int) $user->jwt_version);
            }

            AuditLog::record('sesiones_cerradas', User::class, $user->id, [
                'alcance' => 'otras',
                'sesiones' => $cerradas,
                'tokens_api_revocados' => true,
            ], $user->id);

            return $cerradas;
        });

        return $this->responder($request, $cerradas === 0
            ? 'No había otras sesiones abiertas. Los accesos por API anteriores se revocaron.'
            : 'Se cerraron '.$cerradas.' sesión(es) en otros dispositivos.', ['cerradas' => $cerradas]);
    }

    public function destroy(Request $request, string $identificador): RedirectResponse|JsonResponse
    {
        $this->validarClave($request);

        /** @var User $user */
        $user = $request->user();
        $sesionActual = $request->session()->getId();

        DB::transaction(function () use ($user, $sesionActual, $identificador): void {
            if (! SesionesActivas::cerrarUna($user, $sesionActual, $identificador)) {
                abort(404, 'La sesión no existe o ya se cerró.');
            }

            AuditLog::record('sesiones_cerradas', User::class, $user->id, [
                'alcance' => 'una',
                'sesiones' => 1,
                'tokens_api_revocados' => false,
            ], $user->id);
        });

        return $this->responder($request, 'La sesión se cerró.', ['cerradas' => 1]);
    }

    private function validarClave(Request $request): void
    {
        // Bolsa de errores propia: el formulario de perfil de la misma página también tiene `current_password`.
        $request->validateWithBag('sesiones', [
            'current_password' => ['required', 'string', 'current_password'],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para cerrar sesiones.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function responder(Request $request, string $mensaje, array $datos): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $mensaje] + $datos)
            : redirect()->route('admin.perfil.edit')->with('success', $mensaje);
    }
}
