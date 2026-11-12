<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Fixture opt-in del Libro de Reclamaciones (E2): solo servidor PHP integrado en loopback, SQLite EN MEMORIA
// reconstruida en cada petición, sin correo ni HTTP externo. El módulo se activa SOLO dentro de este proceso
// (config en tiempo de ejecución); config/reclamaciones.php y los .env no se tocan.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_RECLAMACIONES_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$hot = $root.'/tests/Support/.reclamaciones-fixture-no-hot';
if (is_file($root.'/bootstrap/cache/config.php') || is_file($hot)) {
    throw new RuntimeException('Browser fixture refuses cached configuration or a Vite hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
$port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
    'APP_URL' => 'http://127.0.0.1:'.$port, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'OPENAI_API_KEY' => '', 'MATRICULA_MAIL_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($hot);
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Browser fixture requires SQLite in memory.');
}
Http::preventStrayRequests();
Mail::fake();
Artisan::call('migrate', ['--force' => true]);

// Encendido solo en este proceso. Reclamaciones::habilitado() exige un mailer «real»: se declara smtp hacia un
// puerto local cerrado y, como reclamaciones.correo.enabled sigue en false, no se despacha ningún envío.
if (getenv('HORUS_RECLAMACIONES_FIXTURE_MODO') === 'encendido') {
    config([
        'reclamaciones.enabled' => true,
        'reclamaciones.correo.enabled' => false,
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.from.address' => 'libro@horus.test',
    ]);
}

$app->handleRequest(Request::capture());
