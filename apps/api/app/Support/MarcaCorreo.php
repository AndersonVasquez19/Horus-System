<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marca compartida por los correos transaccionales (bienvenida de matrícula, certificado, recuperar contraseña).
 *
 * Un correo no puede usar archivos locales: el logo se pide a una dirección pública. Sale de `APP_URL` (la API sirve
 * `public/assets/…`) y SOLO se usa si es una URL https que no sea de la máquina local; si no (desarrollo, `APP_URL`
 * sin configurar) la plantilla muestra el nombre en texto y nunca una imagen rota ni una dirección inventada. Se puede
 * forzar otra dirección con `MAIL_LOGO_URL`.
 */
final class MarcaCorreo
{
    private const RUTA_LOGO = '/assets/images/email/logo-horus-email.png';

    public const TELEFONO = '+51 972 021 374';

    public const WHATSAPP = 'https://wa.me/51972021374';

    public const CORREO = 'horusgroupcajamarca@gmail.com';

    public static function logoUrl(): ?string
    {
        $forzada = trim(Entrada::texto(config('mail.logo_url')));
        $url = $forzada !== '' ? $forzada : rtrim(Entrada::texto(config('app.url')), '/').self::RUTA_LOGO;

        $partes = parse_url($url);
        $host = is_array($partes) ? strtolower((string) ($partes['host'] ?? '')) : '';
        $esLocal = $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.test') || str_ends_with($host, '.local');

        return is_array($partes) && ($partes['scheme'] ?? '') === 'https' && ! $esLocal ? $url : null;
    }
}
