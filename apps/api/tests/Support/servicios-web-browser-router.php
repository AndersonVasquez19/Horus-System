<?php

declare(strict_types=1);

use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Exclusivo de http://127.0.0.1:8774; no atiende la aplicación ni una base real.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_SERVICIOS_WEB_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8774'
    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T[0-9:.]+Z\z/D', (string) getenv('HORUS_SERVICIOS_WEB_FIXTURE_TIME'))
    || (getenv('HORUS_PG_TEST_PROFILE') !== false && getenv('HORUS_PG_TEST_PROFILE') !== '')) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php') || is_file($root.'/tests/Support/.servicios-web-fixture-no-hot')) {
    throw new RuntimeException('Fixture refuses cached configuration or an unexpected hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if ($path !== '/tecnologias/soporte-mantenimiento' || ! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_URL' => 'http://127.0.0.1:8774', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'OPENAI_API_KEY' => '', 'MATRICULA_MAIL_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.servicios-web-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('catalogo-publico.enabled') !== true || config('horus-advisor.enabled') || config('chatbot.public_catalog_enabled')
    || config('catalogo-servicios.enabled') || config('registro-verificacion.enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires in-memory SQLite, the enabled course web catalog default and disabled service/AI/mail flags.');
}
Http::preventStrayRequests();
Carbon::setTestNow(Carbon::parse((string) getenv('HORUS_SERVICIOS_WEB_FIXTURE_TIME')));
Artisan::call('migrate', ['--force' => true]); // Solo la conexión SQLite :memory: verificada arriba.
(new RolesAndPermissionsSeeder)->run();
$caso = $_SERVER['HTTP_X_HORUS_FIXTURE_CATALOG'] ?? 'off';
if (! in_array($caso, ['off', 'on', 'empty', 'missing', 'off-missing'], true)) {
    http_response_code(400);
    exit;
}
if (in_array($caso, ['missing', 'off-missing'], true)) {
    Schema::drop('servicio_publicaciones');
}
if ($caso === 'on') {
    (new RolesAndPermissionsSeeder)->run();
    $autor = User::factory()->create();
    $autor->assignRole('admin');
    foreach (['Redes de laboratorio ficticias', 'Diagnóstico de ejemplo', str_repeat('Título', 18)] as $indice => $titulo) {
        $servicio = ServicioTecnologico::forceCreate(['codigo' => 'FICTICIO-'.$indice,
            'identificador_publico' => Str::uuid()->toString(), 'nombre' => 'Interno ficticio',
            'categoria' => 'Interno', 'estado' => 'activo', 'revision' => 1]);
        ServicioPublicacion::forceCreate(['servicio_tecnologico_id' => $servicio->id, 'titulo' => $titulo,
            'resumen' => $indice === 2 ? str_repeat('Contenido', 50) : 'Ficha editorial ficticia para comprobar la presentación pública.',
            'descripcion' => $indice === 2 ? str_repeat('DetalleExtenso', 300) : "Descripción de ejemplo.\nTexto publicado en dos líneas.",
            'categoria' => $indice === 0 ? 'Laboratorio ficticio' : null,
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addDay()->toDateString(),
            'estado' => 'publicada', 'autorizado_por' => $autor->id, 'autorizado_at' => now()]);
    }
}
// Simulación por petición, sin modificar configuración ni archivos del entorno.
config(['catalogo-servicios.enabled' => ! in_array($caso, ['off', 'off-missing'], true)]);
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-servicios-web');
$response->send();
$kernel->terminate($request, $response);
