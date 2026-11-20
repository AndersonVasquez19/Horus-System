<?php

/*
 * Content-Security-Policy.
 *
 * CSP_MODE:
 *   - report-only (por defecto): envía `Content-Security-Policy-Report-Only`. El navegador NO bloquea nada; solo
 *     avisa en la consola de lo que bloquearía (filtrar por «Content Security Policy»).
 *   - enforce: envía `Content-Security-Policy`. El navegador BLOQUEA lo que no cumpla la política. Probarlo antes
 *     en local y volver a `report-only` ante cualquier fallo.
 *   - off: no envía ninguna cabecera CSP.
 * Cualquier otro valor se trata como `off`: un error al escribirlo nunca bloquea. Con `config:cache`, tras cambiar
 * el valor hay que ejecutar `php artisan config:clear`.
 *
 * Cada directiva es una lista de fuentes. Laravel ya no sirve páginas con scripts (solo errores y correos): la
 * política es estricta y no admite `unsafe-eval`.
 */
return [
    'mode' => env('CSP_MODE', 'report-only'),

    'directives' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        // 'unsafe-inline': las páginas de error llevan sus estilos en línea.
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'frame-src' => ["'none'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ],
];
