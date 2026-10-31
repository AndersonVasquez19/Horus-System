<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marca de tiempo firmada para el formulario público: «segundos.firma». Se emite al mostrar el formulario
 * y se exige que haya transcurrido un mínimo (los bots rellenan al instante) y un máximo (marcas reutilizadas).
 * La firma es HMAC-SHA256 con la clave de la aplicación; no contiene datos personales.
 */
final class AntispamReclamaciones
{
    public static function emitirMarca(?int $ahora = null): string
    {
        $ts = (string) ($ahora ?? time());

        return $ts.'.'.self::firma($ts);
    }

    public static function marcaValida(mixed $marca, ?int $ahora = null): bool
    {
        if (! is_string($marca) || ! preg_match('/\A(\d{1,12})\.([0-9a-f]{64})\z/D', $marca, $m)) {
            return false;
        }
        if (! hash_equals(self::firma($m[1]), $m[2])) {
            return false;
        }
        $transcurrido = ($ahora ?? time()) - (int) $m[1];

        return $transcurrido >= (int) config('reclamaciones.antispam.segundos_minimos', 5)
            && $transcurrido <= (int) config('reclamaciones.antispam.segundos_maximos', 7200);
    }

    private static function firma(string $ts): string
    {
        $clave = config('app.key');
        abort_unless(is_string($clave) && $clave !== '', 503, 'Configuración de integridad no disponible.');

        return hash_hmac('sha256', 'reclamaciones-marca:'.$ts, $clave);
    }
}
