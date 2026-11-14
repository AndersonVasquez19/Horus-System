<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use App\Support\CredentialTiming;
use App\Support\Email;
use App\Support\PasswordPolicy;
use App\Support\RestablecimientoContrasena;
use App\Support\SesionPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de los paneles de apps/web sobre la sesión web de Laravel.
 *
 * El BFF de Next guarda la cookie de sesión (HttpOnly) del navegador y la reenvía; las mutaciones exigen el token
 * CSRF de esa sesión (ValidateCsrfToken del grupo `web`). Ver docs/arquitectura-paneles.md.
 */
final class AutenticacionController extends Controller
{
    private const CREDENCIALES_INVALIDAS = 'Las credenciales ingresadas no son válidas.';

    /** Inicia (o retoma) una sesión anónima y devuelve su token CSRF. */
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(Request $request): JsonResponse
    {
        Email::normalizeRequest($request);
        $credenciales = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        // Igualar el trabajo de hash cuando la cuenta no existe: no revelar por latencia qué cuentas hay.
        $cuentaExiste = User::query()->where('email', $credenciales['email'])->exists();
        if (! $cuentaExiste) {
            CredentialTiming::check($credenciales['password'], null);
        }

        // Una cuenta sin permiso de entrada a ningún panel recibe la misma respuesta que unas credenciales
        // incorrectas y no abre sesión.
        $aceptada = Auth::guard('web')->attemptWhen(
            ['email' => $credenciales['email'], 'password' => $credenciales['password']],
            fn (User $user): bool => SesionPanel::tieneAccesoAlgunPanel($user),
        );

        if (! $aceptada) {
            AuditLog::record('login_failed', User::class, null, [
                'canal' => 'panel',
                'cuenta_existente' => $cuentaExiste,
                'huella_correo' => substr(hash_hmac('sha256', $credenciales['email'], (string) config('app.key')), 0, 16),
            ], null);

            throw ValidationException::withMessages(['email' => self::CREDENCIALES_INVALIDAS]);
        }

        // Nuevo identificador de sesión y nuevo token CSRF: evita la fijación de sesión.
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        SesionPanel::iniciar($request);

        /** @var User $user */
        $user = $request->user();
        AuditLog::record('login', User::class, $user->getKey(), ['canal' => 'panel', 'personal_panel' => true]);

        return response()->json(['data' => SesionPanel::representar($request, $user)]);
    }

    public function sesion(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => SesionPanel::representar($request, $user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        AuditLog::record('logout', User::class, $user->getKey(), ['canal' => 'panel']);
        // Igual que el cierre de sesión web: revoca también los JWT vigentes de la cuenta.
        $user->increment('jwt_version');
        SesionPanel::cerrar($request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** Siempre la misma respuesta, exista o no la cuenta (incluido el límite del broker). */
    public function solicitarRestablecimiento(Request $request): JsonResponse
    {
        Email::normalizeRequest($request);
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ]);

        RestablecimientoContrasena::solicitar($datos['email'], function (User $user, string $token): void {
            $user->notify(new RestablecerContrasenaWebNotification($token));
        });

        return response()->json(['message' => RestablecimientoContrasena::MENSAJE_SOLICITUD], 202);
    }

    public function restablecer(Request $request): JsonResponse
    {
        Email::normalizeRequest($request);
        $datos = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ], [
            'token.required' => 'El enlace de recuperación no es válido.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ] + PasswordPolicy::messages());

        $estado = RestablecimientoContrasena::restablecer([
            'email' => $datos['email'],
            'password' => $datos['password'],
            'password_confirmation' => $request->input('password_confirmation'),
            'token' => $datos['token'],
        ]);

        if ($estado !== Password::PASSWORD_RESET) {
            // Mensaje único para correo inexistente, token inválido o limitación.
            throw ValidationException::withMessages(['token' => 'El enlace de recuperación no es válido o expiró. Solicita uno nuevo.']);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
