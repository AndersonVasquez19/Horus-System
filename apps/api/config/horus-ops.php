<?php

return [
    // Preparación solamente: ningún proceso o renovación se inicia al cargar configuración.
    'enabled' => (bool) env('HORUS_OPS_ENABLED', false),
    'renew_catalog' => (bool) env('HORUS_OPS_RENEW_CATALOG', false),
];
