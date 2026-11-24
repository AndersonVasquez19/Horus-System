<?php

return [
    // Envío del certificado por correo (enlace de verificación). Apagado por defecto: ver docs/runbook-correo-resend.md.
    'enabled' => env('CERTIFICADO_MAIL_ENABLED', false),
    // Si además se envía solo al emitir. Apagado: el personal lo envía con el botón «Enviar por correo».
    'auto' => env('CERTIFICADO_MAIL_AUTO', false),
    'queue' => env('CERTIFICADO_MAIL_QUEUE', 'default'),
];
