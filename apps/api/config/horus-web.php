<?php

return [
    // URL pública del frontend Next.js (apps/web), sin barra final. Vacía = las páginas retiradas de Blade
    // responden 404 (no hay a dónde redirigir). Debe configurarse antes de desplegar: ver PROJECT_CONTEXT.md.
    'url' => rtrim((string) env('HORUS_WEB_URL', ''), '/'),

    // Secreto compartido con el BFF de apps/web (cabecera X-Horus-Web-Token) para /api/public/v1/*. Mínimo 32
    // caracteres. Vacío o más corto = esas rutas responden 404. Solo vive en el servidor: nunca llega al navegador.
    'service_token' => (string) env('HORUS_WEB_SERVICE_TOKEN', ''),
];
