<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use App\Services\AdminProtection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Respuesta a intentos fallidos de acceso sobre cuentas existentes (alertas internas opt-in y bloqueo automático opt-in).
 *
 * Bloqueo automático (apagado por defecto), diseñado para que no sea un arma contra las propias cuentas:
 *  - El contador es por **cuenta + IP** y de **ventana fija** (no se renueva con cada fallo): quien falla desde otra IP no bloquea a
 *    la persona real, y una ráfaga no mantiene la cuenta cerrada indefinidamente.
 *  - Bloquea solo al **alcanzar** el umbral dentro de la ventana, así que al terminar el bloqueo (o desbloquear) hace falta una
 *    ráfaga nueva completa para volver a bloquear.
 *  - Las cuentas **administradoras no se bloquean** automáticamente (un bloqueo masivo dejaría el panel sin administración; las
 *    protegen el límite de intentos del login y, si se activa, el 2FA). Se desbloquea además por consola: `horus:desbloquear-usuario`.
 */
final class SeguridadCuentas
{
    private static function ip(): string
    {
        return (string) (request()->ip() ?? 'sin-ip');
    }

    private static function claveIntentos(int $userId, string $ip): string
    {
        return 'horus:login_fail:'.$userId.':'.substr(hash('sha256', $ip), 0, 16);
    }

    /** Reinicia el contador de la cuenta desde la IP actual (los de otras IP caducan solos con su ventana). */
    public static function limpiarIntentos(User $user): void
    {
        Cache::forget(self::claveIntentos((int) $user->getKey(), self::ip()));
    }

    public static function registrarFalloParaCuentaExistente(string $email): void
    {
        $user = User::query()->where('email', $email)->whereNull('deleted_at')->first();
        if (! $user instanceof User) {
            return;
        }

        $ventanaMinutos = max(5, (int) config('seguridad.bloqueo_minutos', 15));
        $umbral = max(3, (int) config('seguridad.bloqueo_intentos', 5));
        $clave = self::claveIntentos((int) $user->getKey(), self::ip());

        // Ventana fija: el TTL se fija una sola vez al primer fallo y no se renueva.
        Cache::add($clave, 0, now()->addMinutes($ventanaMinutos));
        $actual = (int) Cache::increment($clave);

        // Solo al alcanzar el umbral (no «a partir de»): no hay re-bloqueo inmediato al expirar o desbloquear.
        if ($actual !== $umbral) {
            return;
        }

        if (config('seguridad.bloqueo_auto_enabled') === true
            && ($user->bloqueado_hasta === null || $user->bloqueado_hasta->isPast())
            && ! app(AdminProtection::class)->isAdmin($user)) {
            $hasta = now()->addMinutes($ventanaMinutos);
            DB::transaction(function () use ($user, $hasta): void {
                $fresco = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
                if (! $fresco instanceof User) {
                    return;
                }
                $fresco->forceFill([
                    'bloqueado_hasta' => $hasta,
                    'motivo_bloqueo' => 'Bloqueo temporal automático por intentos fallidos reiterados.',
                    'jwt_version' => (int) $fresco->jwt_version + 1,
                ])->save();
                SessionRevoker::revokeSessions($fresco);
                AuditLog::record('user_locked_auto', User::class, $fresco->id, [
                    'bloqueado_hasta' => $hasta->toIso8601String(),
                ], null);
            });
        }

        if (config('seguridad.alertas_login_enabled') === true) {
            self::emitirAlertaAccesos($user, $actual);
        }
    }

    private static function emitirAlertaAccesos(User $afectado, int $intentos): void
    {
        $destinatarios = [$afectado->id];
        if (class_exists(RolesAndPermissionsSeeder::class)) {
            $admins = User::query()->whereNull('deleted_at')->whereHas('roles', fn ($q) => $q->where('name', 'admin')->where('guard_name', 'web'))->pluck('id')->all();
            $destinatarios = array_values(array_unique([...$destinatarios, ...$admins]));
        }

        foreach ($destinatarios as $uid) {
            NotificacionInterna::crearPersonal((int) $uid, [
                'titulo' => 'Alerta de seguridad: intentos fallidos de acceso',
                'mensaje' => "Se detectaron {$intentos} intentos fallidos recientes sobre la cuenta {$afectado->name}.",
                'tipo' => 'danger',
                'vinculo' => '/admin/academico/auditoria?accion=login_failed',
            ]);
        }
    }
}
