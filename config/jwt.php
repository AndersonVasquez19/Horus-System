<?php

return [
    'secret' => env('JWT_SECRET') ?: env('APP_KEY'),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'ttl' => (int) env('JWT_TTL', 900),
    'leeway' => (int) env('JWT_LEEWAY', 0),
];
