<?php

return [
    // Activar solo tras revisar remitente/dominio, migración, worker y scheduler.
    'enabled' => env('MATRICULA_MAIL_ENABLED', false),
    'connection' => env('MATRICULA_MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('MATRICULA_MAIL_QUEUE', 'default'),
];
