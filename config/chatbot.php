<?php

return [
    // Sin autorización expresa para cambiar contenido público, conservar las FAQ del Sprint 04.
    'public_catalog_enabled' => false,
    // Solo metadatos: nunca almacenar el texto libre de una conversación.
    'duplicate_seconds' => 60,
    'context_seconds' => 900,
    'openai_per_minute' => 10,
    'openai_per_session' => 3,
    'max_context_chars' => 6000,
    'max_answer_chars' => 1800,
];
