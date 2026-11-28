<?php

return [
    // Activación explícita por instalación, autorizada por el propietario. Apagado por defecto.
    // El módulo sigue inactivo si falta el envío de correos, el correo de la empresa o algún dato del proveedor
    // (ver App\Support\Reclamaciones).
    'enabled' => (bool) env('RECLAMACIONES_ENABLED', false),

    // Encabezado de la hoja (Anexo I). Los aporta el propietario; no se inventan valores (decisión A1).
    'proveedor' => [
        'razon_social' => env('RECLAMACIONES_RAZON_SOCIAL'),
        'ruc' => env('RECLAMACIONES_RUC'),
        'direccion' => env('RECLAMACIONES_DIRECCION'),
        'codigo_libro' => env('RECLAMACIONES_CODIGO_LIBRO'),
    ],

    // Versión de la Política de Privacidad publicada en la web que se informó al consumidor (máximo 20 caracteres).
    'privacidad_version' => '2026-11-28',

    // Propuestas de solución (con sus aceptaciones y rechazos). Apagadas: hoy suspenden el plazo al registrarse,
    // aunque el correo no llegue a salir.
    'propuestas_habilitadas' => false,

    // Plazos en días hábiles (lunes a viernes salvo feriados). La definición legal de «día hábil» no está
    // verificada (decisión E5); los feriados se cargan aquí (decisión B5) como lista 'AAAA-MM-DD'.
    'plazo_dias_habiles' => 15,
    'suspension_dias_habiles' => 5,
    // Avisos internos del plazo legal (Sprint 22): días hábiles previos al vencimiento, además del día del vencimiento.
    'aviso_dias_habiles' => 3,
    // Feriados nacionales (D. Leg. 713 y leyes posteriores); no incluye los días no laborables del sector público.
    // Tomados de portales no oficiales el 28/11/2026: confirmar, y añadir 2028 antes de que termine 2027.
    'feriados' => [
        '2026-01-01', '2026-04-02', '2026-04-03', '2026-05-01', '2026-06-07', '2026-06-29', '2026-07-23', '2026-07-28',
        '2026-07-29', '2026-08-06', '2026-08-30', '2026-10-08', '2026-11-01', '2026-12-08', '2026-12-09', '2026-12-25',
        '2027-01-01', '2027-03-25', '2027-03-26', '2027-05-01', '2027-06-07', '2027-06-29', '2027-07-23', '2027-07-28',
        '2027-07-29', '2027-08-06', '2027-08-30', '2027-10-08', '2027-11-01', '2027-12-08', '2027-12-09', '2027-12-25',
    ],
    'zona_horaria' => 'America/Lima',

    'correo' => [
        // Envío por la cola de Laravel. Apagado: las filas de reclamacion_envios quedan pendientes y no se despacha nada.
        'enabled' => (bool) env('RECLAMACIONES_CORREO_ENABLED', false),
        // Buzón de la empresa: recibe el aviso de cada hoja y es el «Responder a» de los correos al consumidor.
        'empresa' => env('RECLAMACIONES_CORREO_EMPRESA'),
        'max_intentos' => 4,
        'retroceso_segundos' => [60, 300, 900],
        // Un envío «procesando» más antiguo que esto se considera abandonado y puede reservarse de nuevo.
        'reserva_minutos' => 10,
        'cola' => env('RECLAMACIONES_CORREO_COLA', 'default'),
    ],

    'antispam' => [
        'honeypot' => 'sitio_web',
        'segundos_minimos' => 5,
        'segundos_maximos' => 7200,
        'limite_ip_10min' => 3,
        'limite_ip_dia' => 10,
        'limite_correo_dia' => 3,
    ],
];
