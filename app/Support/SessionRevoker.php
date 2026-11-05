<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoca las credenciales vivas de una cuenta cuando cambia su contraseña:
 * JWT (sube jwt_version) y sesiones web guardadas en base de datos.
 *
 * Las sesiones solo se pueden cerrar desde el servidor con SESSION_DRIVER=database (el valor por
 * defecto del proyecto). Con otro driver (file, redis, cookie) solo se revocan los JWT.
 */
final class SessionRevoker
{
    /** @param string|null $exceptSessionId sesión actual que debe conservarse (p. ej. quien cambia su propia clave) */
    public static function revokeSessions(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $query = DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
    }
}
