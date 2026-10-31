<?php

return [
    // Libro de Reclamaciones (Sprint 20, Fase 2). Apagado por defecto y sin lectura de entorno:
    // la activación real exige decisiones del propietario y revisión legal (PROJECT_CONTEXT, Fase 1.2 §10).
    // Con false no se procesa ni se guarda ninguna reclamación pública. Aunque se ponga en true,
    // el módulo sigue inactivo mientras el correo no esté configurado de verdad (ver App\Support\Reclamaciones).
    'enabled' => false,

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
    'feriados' => [],
    'zona_horaria' => 'America/Lima',

    'correo' => [
        // Envío por la cola de Laravel. Apagado: las filas de reclamacion_envios quedan pendientes y no se despacha nada.
        'enabled' => false,
        'max_intentos' => 4,
        'retroceso_segundos' => [60, 300, 900],
        // Un envío «procesando» más antiguo que esto se considera abandonado y puede reservarse de nuevo.
        'reserva_minutos' => 10,
        'cola' => 'default',
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
