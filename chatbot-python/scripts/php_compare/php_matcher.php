<?php

declare(strict_types=1);

/*
 * Comparación SOLO LECTURA del motor local del chatbot PHP de Horus-System.
 *
 * Qué ejecuta: AsistenteVirtualService::resolver() (privado, vía reflexión) con las intenciones
 * incorporadas de App\Support\ChatKnowledge, igual que el flujo público tras sus validaciones.
 *
 * Qué NO hace (garantías verificadas antes de procesar):
 *   - No modifica archivos de Laravel ni su configuración.
 *   - No toca la base de datos real: fuerza DB_CONNECTION=sqlite y DB_DATABASE=:memory:.
 *   - No llama a OpenAI: OPENAI_API_KEY vacía (el servicio devuelve su respuesta de respaldo).
 *   - No escribe logs (LOG_CHANNEL=null), ni caché persistente (CACHE_STORE=array), ni correo.
 *   - No usa ChatLog, Cache::lock ni las intenciones personalizadas de la tabla chat_intents.
 *
 * Uso: php php_matcher.php <raiz-laravel> <entrada.json> <salida.json>
 */

[$script, $root, $input, $output] = $argv + [null, null, null, null];
if (! $root || ! $input || ! $output) {
    fwrite(STDERR, "Uso: php php_matcher.php <raiz-laravel> <entrada.json> <salida.json>\n");
    exit(1);
}

$overrides = [
    'APP_ENV' => 'local',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'null',
    'LOG_STACK' => 'null',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'OPENAI_API_KEY' => '',
    'RESEND_API_KEY' => '',
    'MATRICULA_MAIL_ENABLED' => 'false',
    'HORUS_PG_TEST_PROFILE' => '',
];
foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel interpreta la cadena "null" como valor nulo; el canal descartador se fija en memoria.
config(['logging.default' => 'null', 'logging.channels.stack.channels' => ['null']]);

// Salvaguardas: si una configuración cacheada ignorara las variables anteriores, se aborta.
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    fwrite(STDERR, "Abortado: la base efectiva no es SQLite en memoria.\n");
    exit(2);
}
if ((string) config('services.openai.api_key') !== '') {
    fwrite(STDERR, "Abortado: la clave de OpenAI no está vacía.\n");
    exit(3);
}
if (config('logging.default') !== 'null' || config('cache.default') !== 'array') {
    fwrite(STDERR, 'Abortado: logs o caché no están aislados (log='.var_export(config('logging.default'), true)
        .', cache='.var_export(config('cache.default'), true).").\n");
    exit(4);
}

$service = $app->make(App\Services\AsistenteVirtualService::class);
$intents = array_map(
    fn (array $definition) => new App\Models\ChatIntent([...$definition, 'activo' => true]),
    App\Support\ChatKnowledge::intents()
);
$resolver = new ReflectionMethod($service, 'resolver');

$resolve = function (string $message, ?string $previous) use ($service, $resolver, $intents): array {
    // Igual que procesarMensaje(): rechazo de datos personales antes de resolver.
    if (preg_match('/@|\d[\d\s-]{6,}\d/u', $message)) {
        return ['respuesta' => 'Por privacidad, no incluyas datos personales.', 'intent' => null, 'fuente' => 'respaldo'];
    }
    $text = $service->normalizarTexto($message);

    return $resolver->invoke($service, $text, $intents, $previous, 'comparacion', [], false);
};
// Contexto PHP: solo guarda la última intención (guardarContexto()).
$nextContext = fn (?string $previous, ?string $intent): ?string => $intent === null
    ? null
    : (in_array($intent, ['saludo', 'despedida'], true) ? $previous : $intent);

$cases = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
$results = [];
foreach ($cases['items'] as $item) {
    $previous = null;
    foreach ($item['history'] ?? [] as $turn) {
        $previous = $nextContext($previous, $resolve((string) $turn, $previous)['intent']);
    }
    $start = hrtime(true);
    $reply = $resolve((string) $item['query'], $previous);
    $results[] = ['id' => $item['id'], 'ms' => round((hrtime(true) - $start) / 1e6, 3)] + $reply;
}
foreach ($cases['conversations'] as $conversation) {
    $previous = null;
    $turns = [];
    foreach ($conversation['turns'] as $turn) {
        $reply = $resolve((string) $turn, $previous);
        $previous = $nextContext($previous, $reply['intent']);
        $turns[] = $reply;
    }
    $results[] = ['id' => $conversation['id'], 'turns' => $turns];
}

file_put_contents($output, json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fwrite(STDOUT, 'Procesados '.count($results)." casos sin BD real, sin OpenAI y sin logs.\n");
