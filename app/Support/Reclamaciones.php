<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Estado de activación del Libro de Reclamaciones.
 *
 * El módulo solo está activo si el flag es exactamente true Y el correo está configurado de verdad:
 * la copia automática de la hoja es obligatoria (art. 4-B del DS 011-2011-PCM), de modo que sin correo
 * real el libro virtual no puede operar y rige el libro físico.
 */
final class Reclamaciones
{
    public static function habilitado(): bool
    {
        return config('reclamaciones.enabled') === true && self::correoConfigurado();
    }

    /** El envío por cola solo se despacha con el flag de correo en true y un mailer real. */
    public static function envioHabilitado(): bool
    {
        return config('reclamaciones.correo.enabled') === true && self::correoConfigurado();
    }

    public static function correoConfigurado(): bool
    {
        $mailer = config('mail.default');
        $remitente = config('mail.from.address');

        return is_string($mailer) && ! in_array($mailer, ['', 'log', 'array', 'null'], true)
            && is_string($remitente) && filter_var($remitente, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with($remitente, '@example.com');
    }

    public static function maxIntentos(): int
    {
        return max(1, (int) config('reclamaciones.correo.max_intentos', 4));
    }
}
