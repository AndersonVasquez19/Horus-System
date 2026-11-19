<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dirección del frontend Next.js (apps/web), la única interfaz web de Horus. Laravel ya no sirve páginas: cuando necesita
 * devolver a una persona a la interfaz (tras iniciar sesión, por ejemplo) construye la URL con `HORUS_WEB_URL`.
 * Sin una URL http/https válida devuelve la raíz relativa `/`, nunca una dirección inventada.
 */
final class UrlWeb
{
    public static function ruta(string $ruta = '/'): string
    {
        $base = (string) config('horus-web.url');
        $ruta = '/'.ltrim($ruta, '/');

        return preg_match('#^https?://[^\s/]+#i', $base) === 1 ? $base.$ruta : '/';
    }
}
