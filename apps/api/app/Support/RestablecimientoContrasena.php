<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Recuperación de contraseña compartida por el formulario Blade y por la API del panel.
 *
 * Una sola implementación de la regla: el restablecimiento revoca los JWT (jwt_version + 1) y las sesiones web,
 * renueva el token "recordarme", audita sin contraseña ni token y NO verifica el correo (Sprint 14).
 */
final class RestablecimientoContrasena
{
    public const MENSAJE_SOLICITUD = 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja.';

    /**
     * Envía el enlace sin revelar si la cuenta existe: cualquier fallo del proveedor de correo se ve igual que el
     * éxito y se registra sin destinatario, token ni detalle del proveedor.
     *
     * @param  (Closure(User, string): mixed)|null  $notificar  envío alternativo (p. ej. enlace a apps/web)
     */
    public static function solicitar(string $email, ?Closure $notificar = null): void
    {
        try {
            Password::sendResetLink(['email' => $email], $notificar);
        } catch (Throwable $error) {
            Log::warning('auth.password_reset', [
                'outcome' => $error instanceof TransportExceptionInterface ? 'delivery_unavailable' : 'delivery_failed',
            ]);
        }
    }

    /**
     * @param  array{email: string, password: string, password_confirmation: mixed, token: string}  $credenciales
     * @return string estado del broker (Password::PASSWORD_RESET si tuvo éxito)
     */
    public static function restablecer(array $credenciales): string
    {
        return Entrada::texto(DB::transaction(fn () => Password::reset($credenciales, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'jwt_version' => (int) $user->jwt_version + 1,
                'debe_cambiar_password' => false,
            ])->setRememberToken(Str::random(60));

            $user->save();
            SeguridadCuentas::limpiarIntentos($user);
            SessionRevoker::revokeSessions($user);

            // Actor explícito: en el reset no hay sesión autenticada. Sin la contraseña ni el token.
            AuditLog::record('password_reset', $user::class, $user->id, [], $user->id);

            event(new PasswordReset($user));
        })));
    }
}
