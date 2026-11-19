<?php

declare(strict_types=1);

/*
 * Seguridad de cuentas del panel y la API (Ola 3).
 * Las acciones automáticas sobre cuentas (bloqueo automático y alertas masivas) quedan apagadas por defecto hasta su activación explícita.
 */
return [
    'bloqueo_intentos' => max(3, (int) env('SEGURIDAD_BLOQUEO_INTENTOS', 5)),
    'bloqueo_minutos' => max(5, (int) env('SEGURIDAD_BLOQUEO_MINUTOS', 15)),
    'bloqueo_auto_enabled' => (bool) env('SEGURIDAD_BLOQUEO_AUTO_ENABLED', false),
    'alertas_login_enabled' => (bool) env('SEGURIDAD_ALERTAS_LOGIN_ENABLED', false),
    'totp_issuer' => env('APP_NAME', 'Horus Academic'),
];
