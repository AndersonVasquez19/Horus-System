<?php

return [
    // Conocimiento institucional público que Laravel y Horus AI leen por igual (convenios de la web, política de
    // privacidad de la caché y referencias validadas del asistente). Es un archivo del servicio Python, así que la
    // ruta no puede quedar fija en el código: HORUS_AI_KNOWLEDGE_PATH ya la define para Python (`knowledge_path`) y
    // un solo valor configura ambos lados. Vacío o ausente conserva la ubicación actual.
    //
    // Clave propia, fuera de `horus-advisor`: ese arreglo se serializa en la versión de la caché de respuestas y
    // añadirle la ruta la invalidaría y la haría depender de cada máquina.
    'path' => env('HORUS_AI_KNOWLEDGE_PATH') ?: base_path('chatbot-python/data/knowledge.json'),
];
