<?php

/*
 * API de los paneles administrativos de apps/web (/api/panel/v1/*).
 *
 * Solo la consume el BFF de Next.js (servidor a servidor, con X-Horus-Web-Token). La sesión es la sesión web de
 * Laravel (cookie HttpOnly + CSRF); ver docs/arquitectura-paneles.md.
 */
return [
    // Vida máxima de una sesión del panel desde el inicio de sesión, aunque haya actividad. La inactividad la
    // controla SESSION_LIFETIME. Mínimo efectivo: 15 minutos.
    'sesion_absoluta_minutos' => max(15, (int) env('PANEL_SESION_ABSOLUTA_MINUTOS', 720)),
];
