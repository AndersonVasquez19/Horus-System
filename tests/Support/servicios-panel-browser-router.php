<?php

declare(strict_types=1);

use App\Models\AuditLog;
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

// SOLO http://127.0.0.1:8773, opt-in y SQLite :memory: reconstruida por petición.
// Cabeceras de fixture eligen el estado ficticio previo; NO hay persistencia entre peticiones.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_SERVICIOS_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8773'
    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T[0-9:.]+Z\z/D', (string) getenv('HORUS_SERVICIOS_FIXTURE_TIME'))
    || (getenv('HORUS_PG_TEST_PROFILE') !== false && getenv('HORUS_PG_TEST_PROFILE') !== '')) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php') || is_file($root.'/tests/Support/.servicios-fixture-no-hot')) {
    throw new RuntimeException('Fixture refuses cached configuration or an unexpected hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if (! is_string($path) || ! preg_match('#\A/admin/servicios(?:/[1-9][0-9]*(?:/estado|/publicacion(?:/autorizar)?)?)?\z#D', $path)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
    'APP_URL' => 'http://127.0.0.1:8773', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'OPENAI_API_KEY' => '', 'MATRICULA_MAIL_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.servicios-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('catalogo-servicios.enabled') || config('catalogo-publico.enabled') || config('horus-advisor.enabled')
    || config('chatbot.public_catalog_enabled') || config('registro-verificacion.enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires memory SQLite and disabled publication/provider flags.');
}
Http::preventStrayRequests();
// El reloj del fixture debe permanecer cercano al navegador: una hora fija histórica vence sus cookies.
Carbon::setTestNow(Carbon::parse((string) getenv('HORUS_SERVICIOS_FIXTURE_TIME')));
Artisan::call('migrate', ['--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create(['name' => 'Administrador ficticio']);
$admin->assignRole('admin');
$rol = $_SERVER['HTTP_X_HORUS_FIXTURE_ROLE'] ?? 'admin';
$actor = $admin;
if (in_array($rol, ['lector', 'editor', 'publicador', 'sin_permiso'], true)) {
    $actor = User::factory()->create(['name' => 'Personal ficticio']);
    $actor->assignRole('coordinador');
    if ($rol !== 'sin_permiso') {
        $actor->givePermissionTo('servicios.ver');
    }
    if ($rol === 'editor') {
        $actor->givePermissionTo('servicios.editar');
    } elseif ($rol === 'publicador') {
        $actor->givePermissionTo('servicios.publicar');
    }
}
auth()->setUser($actor);
$revision = filter_var($_SERVER['HTTP_X_HORUS_FIXTURE_REVISION'] ?? '2', FILTER_VALIDATE_INT);
if (! is_int($revision) || $revision < 1 || $revision > 20) {
    throw new RuntimeException('Invalid synthetic revision.');
}
$nombre = $revision >= 7 ? 'Version de otro editor' : ($revision >= 3 ? 'Servicio modificado en navegador' : 'Servicio administrativo ficticio');
$servicio = new ServicioTecnologico(['nombre' => $nombre, 'categoria' => 'TI', 'estado' => 'activo']);
$servicio->codigo = 'TEC-FICTICIO';
$servicio->identificador_publico = '11111111-1111-4111-8111-111111111111';
$servicio->revision = $revision;
$servicio->save();
$estado = $_SERVER['HTTP_X_HORUS_FIXTURE_STATE'] ?? 'borrador';
if (! in_array($estado, ['sin_ficha', 'borrador', 'publicada', 'retirada'], true)) {
    throw new RuntimeException('Invalid synthetic publication state.');
}
if ($estado !== 'sin_ficha') {
    $ficha = new ServicioPublicacion([
        'titulo' => $revision >= 4 ? 'Ficha revisada desde navegador' : 'Ficha pública ficticia', 'resumen' => 'Resumen ficticio para pruebas de panel.', 'descripcion' => 'Descripción ficticia sin datos comerciales.',
        'categoria' => 'Tecnología', 'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
    ]);
    $ficha->servicio_tecnologico_id = $servicio->id;
    $ficha->estado = $estado;
    if ($estado === 'publicada') {
        $ficha->autorizado_por = $admin->id;
        $ficha->autorizado_at = now();
    }
    $ficha->save();
}
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-servicios');
$response->headers->set('X-Horus-Fixture-Audits', (string) AuditLog::count());
$response->headers->set('X-Horus-Fixture-Editorial', ServicioPublicacion::first()?->estado ?? 'sin_ficha');
$response->send();
$kernel->terminate($request, $response);
