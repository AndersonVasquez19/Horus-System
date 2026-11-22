<?php

return [
    'enabled' => (bool) env('HORUS_ADVISOR_ENABLED', false),
    'url' => env('HORUS_ADVISOR_URL', 'http://127.0.0.1:8001'),
    'token' => env('HORUS_ADVISOR_TOKEN'),
    'signing_key' => env('HORUS_ADVISOR_SIGNING_KEY'),
    // Hostnames exactos de red privada autorizados además de 127.0.0.1 (p. ej. horus-ai.railway.internal),
    // separados por comas. Vacío por defecto: solo loopback. Nunca IP literales ni hosts de metadatos.
    'allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('HORUS_ADVISOR_ALLOWED_HOSTS', '')),
    ))),
    'connect_timeout' => 0.4,
    // Presupuesto compartido entre ready y chat; nunca se reintenta.
    'timeout' => 2.5,
    'max_response_bytes' => 65536,
    // Circuito: tras N fallos de transporte seguidos se responde la contingencia sin llamar durante S segundos.
    'circuit_failures' => 3,
    'circuit_seconds' => 30,
];
