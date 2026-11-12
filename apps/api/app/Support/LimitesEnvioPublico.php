<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Límites de envío de los formularios públicos que llegan por el BFF (Fase 2c.2 en adelante). Se aplican DESPUÉS de
 * validar, para que equivocarse al rellenar no bloquee a la persona: se incrementa primero (operación atómica de la
 * caché) y luego se compara, de modo que ráfagas concurrentes no pasen todas la comprobación. Un reenvío idempotente
 * devuelve su cupo con {@see self::devolver()}.
 */
final class LimitesEnvioPublico
{
    /**
     * @param  array<string, array{0: int, 1: int}>  $limites  clave => [máximo, ventana en segundos]
     * @return string|null clave que superó su máximo (la primera), o null si todos los límites permiten el envío
     */
    public static function consumir(array $limites): ?string
    {
        $superada = null;
        foreach ($limites as $clave => [$maximo, $ventana]) {
            if (RateLimiter::hit($clave, $ventana) > $maximo) {
                $superada ??= $clave;
            }
        }

        return $superada;
    }

    /** @param  array<string, array{0: int, 1: int}>  $limites */
    public static function devolver(array $limites): void
    {
        foreach (array_keys($limites) as $clave) {
            RateLimiter::decrement($clave);
        }
    }

    public static function espera(string $clave): int
    {
        return max(1, RateLimiter::availableIn($clave));
    }
}
