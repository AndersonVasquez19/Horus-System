<?php

return [
    // La bienvenida usa HTTP saliente; no consume webhooks del paquete.
    // Una futura recepción exige autorización e implementación de firma y deduplicación.
    'routes' => false,
];
