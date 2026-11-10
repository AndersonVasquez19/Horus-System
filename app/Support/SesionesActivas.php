<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesiones web vigentes de una cuenta (solo con SESSION_DRIVER=database, el valor por defecto del proyecto).
 *
 * El identificador de sesión es una credencial: nunca sale de aquí. Hacia fuera se usa una huella HMAC con la
 * clave de la aplicación, suficiente para señalar una sesión concreta sin poder reutilizarla. Todas las
 * operaciones se limitan a las filas del usuario indicado.
 */
final class SesionesActivas
{
    public const ZONA = HoraLocal::ZONA;

    public static function disponible(): bool
    {
        return config('session.driver') === 'database';
    }

    public static function huella(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /**
     * @return list<array{identificador: string, ip: string|null, navegador: string|null, ultima_actividad: string, actual: bool}>
     */
    public static function listar(User $user, string $sesionActual): array
    {
        if (! self::disponible()) {
            return [];
        }

        return self::vigentes($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static fn (object $fila): array => [
                'identificador' => self::huella((string) $fila->id),
                'ip' => $fila->ip_address !== null ? (string) $fila->ip_address : null,
                'navegador' => $fila->user_agent !== null ? Str::limit((string) $fila->user_agent, 200) : null,
                'ultima_actividad' => Carbon::createFromTimestamp((int) $fila->last_activity, self::ZONA)->toIso8601String(),
                'actual' => hash_equals((string) $fila->id, $sesionActual),
            ])
            ->values()
            ->all();
    }

    /** Cierra todas las sesiones del usuario salvo la actual. Devuelve cuántas cerró. */
    public static function cerrarOtras(User $user, string $sesionActual): int
    {
        if (! self::disponible()) {
            return 0;
        }

        return self::tabla()
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $sesionActual)
            ->delete();
    }

    /**
     * Cierra una sola sesión del usuario identificada por su huella. La sesión actual no se cierra por esta vía
     * (para eso está el cierre de sesión normal). Devuelve false si la huella no corresponde a otra sesión suya.
     */
    public static function cerrarUna(User $user, string $sesionActual, string $identificador): bool
    {
        if (! self::disponible()) {
            return false;
        }

        $ids = self::tabla()->where('user_id', $user->getKey())->pluck('id');

        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== $sesionActual && hash_equals(self::huella($id), $identificador)) {
                return self::tabla()->where('user_id', $user->getKey())->where('id', $id)->delete() === 1;
            }
        }

        return false;
    }

    /** Filas no caducadas según `session.lifetime` (las caducadas ya no autentican). */
    private static function vigentes(User $user): Builder
    {
        $limite = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();

        return self::tabla()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $limite);
    }

    private static function tabla(): Builder
    {
        return DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'));
    }
}
