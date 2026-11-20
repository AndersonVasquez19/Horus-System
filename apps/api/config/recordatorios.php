<?php

declare(strict_types=1);

/*
 * Recordatorios programados y canales externos (Ola 3).
 *
 * Todas las banderas nacen apagadas (`false`). Antes de activar envío por Resend o WhatsApp Cloud API en producción
 * debe actualizarse el aviso de privacidad vigente (hoy `2026-10-30-borrador`) incluyendo la transferencia internacional
 * hacia Resend / Meta y los respectivos DPA, además de contar con consentimiento explícito (Ley N.º 29733).
 */
return [
    'internos_enabled' => (bool) env('RECORDATORIOS_INTERNOS_ENABLED', false),
    'mail_enabled' => (bool) env('RECORDATORIOS_MAIL_ENABLED', false),

    'cuotas_anticipacion_dias' => max(1, (int) env('RECORDATORIOS_CUOTAS_DIAS', 3)),
    'mensajes_sin_atender_horas' => max(1, (int) env('RECORDATORIOS_MENSAJES_HORAS', 24)),

    'queue_connection' => env('RECORDATORIOS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('RECORDATORIOS_QUEUE', 'default'),

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_CLOUD_ENABLED', false),
        'version' => env('WHATSAPP_CLOUD_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_ID'),
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
        'idioma' => env('WHATSAPP_CLOUD_LANG', 'es_PE'),
    ],

    'webhooks' => [
        'resend_secret' => env('RESEND_WEBHOOK_SECRET'),
        'resend_tolerancia_segundos' => 300,
    ],
];
