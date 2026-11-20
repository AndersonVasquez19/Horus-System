<?php

return [
    // Activación explícita por instalación, autorizada por el propietario. Apagado por defecto.
    // El módulo sigue inactivo sin correo configurado (ver App\Support\Reclamaciones).
    'enabled' => (bool) env('RECLAMACIONES_ENABLED', false),

    // Encabezado de la hoja (Anexo I). Los aporta el propietario; no se inventan valores (decisión A1).
    'proveedor' => [
        'razon_social' => env('RECLAMACIONES_RAZON_SOCIAL'),
        'ruc' => env('RECLAMACIONES_RUC'),
        'direccion' => env('RECLAMACIONES_DIRECCION'),
        'codigo_libro' => env('RECLAMACIONES_CODIGO_LIBRO'),
    ],

    'privacidad_version' => '2026-10-30-borrador',

    // Plazos en días hábiles (lunes a viernes salvo feriados). La definición legal de «día hábil» no está
    // verificada (decisión E5); los feriados se cargan aquí (decisión B5) como lista 'AAAA-MM-DD'.
    'plazo_dias_habiles' => 15,
    'suspension_dias_habiles' => 5,
    // Avisos internos del plazo legal (Sprint 22): días hábiles previos al vencimiento, además del día del vencimiento.
    'aviso_dias_habiles' => 3,
    'feriados' => [],
    'zona_horaria' => 'America/Lima',

    'correo' => [
        // Envío por la cola de Laravel. Apagado: las filas de reclamacion_envios quedan pendientes y no se despacha nada.
        'enabled' => (bool) env('RECLAMACIONES_CORREO_ENABLED', false),
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
