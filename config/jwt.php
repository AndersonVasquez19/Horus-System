<?php

// Sin JWT_SECRET propio, la clave se deriva de APP_KEY con una etiqueta de dominio: la firma de JWT nunca usa
// APP_KEY en crudo (que cifra cookies y firma otros HMAC) y una fuga de uno no permite falsificar el otro.
// Definir JWT_SECRET (≥ 32 bytes) en producción; rotarlo invalida los tokens vigentes (TTL corto por defecto).
$appKey = (string) env('APP_KEY');

return [
    'secret' => env('JWT_SECRET') ?: ($appKey !== '' ? hash_hmac('sha256', 'horus-jwt-v1', $appKey) : null),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    // Acotados en JwtTokenService (TTL 60–86400 s, tolerancia de reloj 0–60 s).
    'ttl' => (int) env('JWT_TTL', 900),
    'leeway' => (int) env('JWT_LEEWAY', 0),
];
