<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Exclusivo: http://127.0.0.1:8765, SQLite :memory: por petición, HTTP Python simulado.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_CHAT_ESTADO_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/_fixture/estado') {
    $value = $_GET['valor'] ?? '';
    if (! in_array($value, ['cargando', 'listo', 'no_disponible'], true)) {
        http_response_code(422);
        exit;
    }
    setcookie('fixture_estado', $value, ['path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    header('Content-Type: application/json');
    echo json_encode(['estado' => $value]);
    exit;
}
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if (is_string($path) && preg_match('~\A/(?:assets|build|storage)/~', $path)) {
    http_response_code(404);
    exit;
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'HORUS_ADVISOR_ENABLED' => 'true', 'HORUS_AI_CATALOG_SYNC_ENABLED' => 'false',
    'HORUS_OPS_ENABLED' => 'false', 'HORUS_OPS_RENEW_CATALOG' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('__chat-estado-fixture-no-env__');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Fixture requires SQLite memory.');
}
config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32)),
    'horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32),
    'horus-advisor.url' => 'http://127.0.0.1:8001', 'horus-catalog.sync_enabled' => false,
    'matricula-mail.enabled' => false, 'registro-verificacion.enabled' => false]);
Http::preventStrayRequests();
$state = $_COOKIE['fixture_estado'] ?? 'listo';
$cases = json_decode((string) file_get_contents($root.'/tests/Fixtures/chat-aclaraciones-replies.json'), true, 512, JSON_THROW_ON_ERROR);
$byQuery = array_column($cases, 'reply', 'query');
Http::fake(function ($request) use ($state, $byQuery, $cases) {
    if (str_ends_with($request->url(), '/ready')) {
        return Http::response(['status' => $state === 'no_disponible' ? 'not_ready' : 'ready',
            'knowledge' => 'loaded', 'auth' => 'configured', 'catalog' => 'none',
            'semantic' => $state === 'cargando' ? 'loading' : 'loaded']);
    }
    if (str_ends_with($request->url(), '/api/v1/info')) {
        return Http::response(['search_mode' => 'hybrid']);
    }
    if (str_ends_with($request->url(), '/api/v1/chat')) {
        return Http::response($byQuery[$request['message']] ?? $cases[2]['reply']);
    }
    throw new RuntimeException('Unexpected fixture HTTP destination.');
});
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.chat-estado-no-hot');
// Sesión array nueva por petición: nonce ficticio compartido solo por esta fixture.
// El middleware CSRF real sigue comprobando la cabecera del formulario.
$app->make('session')->driver()->put('_token', str_repeat('c', 40));
// El estado no consulta tablas de negocio; evita reconstruirlas en cada sondeo.
if ($path !== '/chat/estado') {
    Artisan::call('migrate', ['--force' => true]);
}
$app->handleRequest(Request::capture());
