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
 * Cada directiva es una lista de fuentes. En desarrollo se añade automáticamente el origen de Vite (`public/hot`).
 */
return [
    'mode' => env('CSP_MODE', 'report-only'),

    // Archivo que Vite crea con su URL mientras `npm run dev` está activo. Solo se lee fuera de producción.
    'vite_hot_file' => public_path('hot'),

    'directives' => [
        'default-src' => ["'self'"],
        // 'unsafe-eval': Alpine.js (build estándar) evalúa las expresiones de x-data/x-show/@click.
        // Se puede retirar migrando a @alpinejs/csp.
        'script-src' => ["'self'", "'unsafe-eval'"],
        // 'unsafe-inline': hay atributos style="" en vistas y librerías de interfaz.
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        // Mapas de Google incrustados en Contactos y en el bloque de horario/ubicación.
        'frame-src' => ['https://www.google.com'],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ],
];
