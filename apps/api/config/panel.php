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

    // Segundos que el tablero guarda sus bloques costosos (dinero, cuotas, asistencia). 0 = sin caché. La clave cambia en cada
    // escritura de matrículas, pagos, ediciones, sesiones o asistencias, así que no se ve un dato viejo tras una operación.
    'resumen_cache_segundos' => max(0, (int) env('PANEL_RESUMEN_CACHE_SEGUNDOS', 30)),

    // Desde este importe (soles) un reverso de pago exige la aprobación de Gerencia (otro admin). 0 la apaga. Ver AprobacionReverso.
    'aprobacion_reverso_umbral' => max(0, (float) env('PANEL_APROBACION_REVERSO_UMBRAL', 100)),
];
