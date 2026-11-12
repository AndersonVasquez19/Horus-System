<?php

return [
    // URL pública del frontend Next.js (apps/web), sin barra final. Vacía = las páginas retiradas de Blade
    // responden 404 (no hay a dónde redirigir). Debe configurarse antes de desplegar: ver PROJECT_CONTEXT.md.
    'url' => rtrim((string) env('HORUS_WEB_URL', ''), '/'),
];
