<?php

declare(strict_types=1);

namespace App\Support;


/**
 * Estado de activación del Libro de Reclamaciones.
 *
 * La recepción pública solo se abre si además del flag está todo lo necesario para entregar la hoja: envío de correos
 * activo y real (la copia automática es obligatoria, art. 4-B del DS 011-2011-PCM), el buzón de la empresa y los
 * datos del proveedor que encabezan la hoja. Así no se aceptan hojas que no se puedan entregar.
 */
final class Reclamaciones
{
    private const DATOS_PROVEEDOR = ['razon_social', 'ruc', 'direccion', 'codigo_libro'];

    /** Recepción pública de hojas nuevas. */
    public static function habilitado(): bool
    {
        return self::moduloActivo() && self::envioHabilitado() && self::correoEmpresa() !== null && self::proveedorCompleto();
    }

    /**
     * El libro está en uso: los casos ya registrados siguen con sus plazos y avisos aunque se pause la recepción
     * (por ejemplo, al apagar el envío).
     */
    public static function moduloActivo(): bool
    {
        return config('reclamaciones.enabled') === true && self::correoConfigurado();
    }

    /** El envío por cola solo se despacha con el flag de correo en true y un mailer real. */
    public static function envioHabilitado(): bool
    {
        return config('reclamaciones.correo.enabled') === true && self::correoConfigurado();
    }

    /** Buzón de la empresa, o `null` si falta o no es un correo válido. */
    public static function correoEmpresa(): ?string
    {
        $correo = config('reclamaciones.correo.empresa');

        return is_string($correo) && filter_var(trim($correo), FILTER_VALIDATE_EMAIL) !== false ? trim($correo) : null;
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
        return max(1, Entrada::entero(config('reclamaciones.correo.max_intentos', 4)));
    }

    private static function proveedorCompleto(): bool
    {
        foreach (self::DATOS_PROVEEDOR as $dato) {
            $valor = config('reclamaciones.proveedor.'.$dato);
            if (! is_string($valor) || trim($valor) === '') {
                return false;
            }
        }

        return true;
    }
}
