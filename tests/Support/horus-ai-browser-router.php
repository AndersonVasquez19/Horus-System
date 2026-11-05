<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Destino exclusivo del smoke Fase 4C: localhost:8765, SQLite :memory: por petición.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_AI_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite'
    || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Fixture requires SQLite memory.');
}
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.horus-ai-no-hot');
Artisan::call('migrate', ['--force' => true]);
$app->handleRequest(Request::capture());
