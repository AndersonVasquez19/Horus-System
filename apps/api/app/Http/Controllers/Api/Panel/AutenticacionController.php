<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use App\Support\CredentialTiming;
use App\Support\Email;
use App\Support\Entrada;
use App\Support\PasswordPolicy;
use App\Support\RestablecimientoContrasena;
use App\Support\SeguridadCuentas;
use App\Support\SesionPanel;
use App\Support\Totp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
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
            'codigo_2fa' => ['nullable', 'string', 'max:32'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        // Igualar el trabajo de hash cuando la cuenta no existe: no revelar por latencia qué cuentas hay.
        $user = User::query()->where('email', $credenciales['email'])->first();
        $cuentaExiste = $user instanceof User;
        $claveValida = CredentialTiming::check($credenciales['password'], $user?->password);
        $habilitada = $cuentaExiste && SesionPanel::tieneAccesoAlgunPanel($user);

        if (! $claveValida || ! $habilitada) {
            AuditLog::record('login_failed', User::class, null, [
                'canal' => 'panel',
                'cuenta_existente' => $cuentaExiste,
                'huella_correo' => substr(hash_hmac('sha256', $credenciales['email'], Entrada::texto(config('app.key'))), 0, 16),
            ], null);
            if ($cuentaExiste) {
                SeguridadCuentas::registrarFalloParaCuentaExistente($credenciales['email']);
            }

            throw ValidationException::withMessages(['email' => self::CREDENCIALES_INVALIDAS]);
        }

        if ($user->tiene2faActivo()) {
            $codigo = trim((string) ($credenciales['codigo_2fa'] ?? ''));
            if ($codigo === '') {
                return response()->json([
                    'message' => 'Ingresa el código de verificación en dos pasos.',
                    'code' => '2fa_requerido',
                    'errors' => ['codigo_2fa' => ['Ingresa tu código TOTP o un código de recuperación.']],
                ], 422);
            }

            // Límite propio de intentos de 2FA por cuenta (además del `throttle:login`): 5 fallos cada 5 minutos.
            $claveLimite = '2fa-login:'.$user->id;
            if (RateLimiter::tooManyAttempts($claveLimite, 5)) {
                return response()->json([
                    'message' => 'Demasiados intentos con el código de verificación. Espera unos minutos e inténtalo de nuevo.',
                    'code' => '2fa_limite',
                ], 429, ['Retry-After' => (string) RateLimiter::availableIn($claveLimite)]);
            }

            // Un código TOTP no puede usarse dos veces: solo se acepta un paso posterior al último usado (actualización atómica).
            $paso = Totp::pasoValido((string) $user->two_factor_secret, $codigo);
            $validoTotp = $paso !== null && User::query()->whereKey($user->id)
                ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $paso))
                ->update(['two_factor_last_step' => $paso]) === 1;
            $recuperacionRestante = null;
            if (! $validoTotp && is_array($user->two_factor_recovery_codes)) {
                $recuperacionRestante = Totp::consumirCodigoRecuperacion($user->two_factor_recovery_codes, $codigo);
            }

            if (! $validoTotp && $recuperacionRestante === null) {
                AuditLog::record('login_failed', User::class, null, [
                    'canal' => 'panel',
                    'cuenta_existente' => true,
                    'motivo' => '2fa_invalido',
                    'huella_correo' => substr(hash_hmac('sha256', $credenciales['email'], Entrada::texto(config('app.key'))), 0, 16),
                ], null);
                SeguridadCuentas::registrarFalloParaCuentaExistente($credenciales['email']);
                RateLimiter::hit($claveLimite, 300);

                return response()->json([
                    'message' => 'El código de verificación no es válido.',
                    'code' => '2fa_requerido',
                    'errors' => ['codigo_2fa' => ['El código de verificación en dos pasos no es válido.']],
                ], 422);
            }

            RateLimiter::clear($claveLimite);
            if ($recuperacionRestante !== null) {
                $user->forceFill(['two_factor_recovery_codes' => $recuperacionRestante])->save();
                AuditLog::record('two_factor_recovery_used', User::class, $user->id, [], $user->id);
            }
        }

        Auth::guard('web')->login($user);

        // Nuevo identificador de sesión y nuevo token CSRF: evita la fijación de sesión.
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        SesionPanel::iniciar($request);
        SeguridadCuentas::limpiarIntentos($user);

        AuditLog::record('login', User::class, $user->id, ['canal' => 'panel', 'personal_panel' => true]);

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
        AuditLog::record('logout', User::class, $user->id, ['canal' => 'panel']);
        // Igual que el cierre de sesión web: revoca también los JWT vigentes de la cuenta.
        $user->increment('jwt_version');
        SesionPanel::cerrar($request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Registro público desde apps/web. Mismas reglas que el registro Blade y que `POST /api/auth/register`.
     * La cuenta se crea SIN roles ni permisos: no da acceso al panel (eso lo concede un administrador por consola o desde
     * Usuarios y roles) y la respuesta no lleva token ni datos de la cuenta.
     */
    public function registrar(Request $request): JsonResponse
    {
        Email::normalizeRequest($request);
        $datos = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u'],
            // Sin regla de unicidad: un correo ya usado recibe la MISMA respuesta que uno nuevo (no revela si la cuenta existe).
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            'terms' => ['accepted'],
        ], [
            'name.required' => 'Escribe tu nombre para continuar.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.not_regex' => 'El nombre contiene caracteres no permitidos.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'terms.accepted' => 'Debes aceptar el aviso de privacidad para crear tu cuenta.',
        ] + PasswordPolicy::messages());

        // El hash se calcula siempre, exista o no la cuenta, para que el tiempo de respuesta no delate el correo.
        $hash = Hash::make($datos['password']);
        if (! User::query()->where('email', $datos['email'])->exists()) {
            try {
                User::create(['name' => $datos['name'], 'email' => $datos['email'], 'password' => $hash]);
            } catch (UniqueConstraintViolationException) {
                // Otra petición creó la misma cuenta a la vez: se responde igual y no se modifica la existente.
            }
        }

        return response()->json(['message' => 'Cuenta creada. Un administrador te dará acceso al panel cuando corresponda.'], 201);
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
