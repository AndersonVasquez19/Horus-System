<?php

return [
    'enabled' => false,
    'url' => env('HORUS_ADVISOR_URL', 'http://127.0.0.1:8001'),
    'token' => env('HORUS_ADVISOR_TOKEN'),
    'signing_key' => env('HORUS_ADVISOR_SIGNING_KEY'),
    'connect_timeout' => 0.4,
    // Presupuesto compartido entre ready y chat; nunca se reintenta.
    'timeout' => 2.5,
    'max_response_bytes' => 65536,
];
