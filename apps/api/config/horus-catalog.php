<?php

return [
    // Independiente de HORUS_ADVISOR_ENABLED; ambos deben habilitarse para sincronizar.
    'sync_enabled' => (bool) env('HORUS_AI_CATALOG_SYNC_ENABLED', false),
    'sync_timeout' => 25.0,
    'expected_version_cache_key' => 'horus-ai.catalog.expected-version',
];
