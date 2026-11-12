<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\SolicitudCotizacion;
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
use Spatie\Permission\Models\Role;

// SOLO http://127.0.0.1:8775, opt-in y SQLite :memory: reconstruida por petición.
// Cabeceras de fixture eligen el estado ficticio previo; NO hay persistencia entre peticiones.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_COTIZACIONES_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8775'
    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T[0-9:.]+Z\z/D', (string) getenv('HORUS_COTIZACIONES_FIXTURE_TIME'))
    || (getenv('HORUS_PG_TEST_PROFILE') !== false && getenv('HORUS_PG_TEST_PROFILE') !== '')) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php') || is_file($root.'/tests/Support/.cotizaciones-fixture-no-hot')) {
    throw new RuntimeException('Fixture refuses cached configuration or an unexpected hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if (is_string($path) && preg_match('#\A/(?:build|assets)/.+\.(?:css|js|png|jpe?g|svg|webp|ico|woff2?|ttf)\z#iD', $path)
    && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
    && $asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if (! is_string($path) || ! preg_match('#\A/admin/cotizaciones(?:/[0-9a-f-]{36}(?:/estado|/responsable|/seguimientos|/historial)?)?\z#D', $path)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
    'APP_URL' => 'http://127.0.0.1:8775', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
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
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.cotizaciones-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('catalogo-servicios.enabled') || config('catalogo-publico.enabled') || config('horus-advisor.enabled')
    || config('chatbot.public_catalog_enabled') || config('registro-verificacion.enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires memory SQLite and disabled publication/provider flags.');
}
Http::preventStrayRequests();
// El reloj del fixture debe permanecer cercano al navegador: una hora fija histórica vence sus cookies.
Carbon::setTestNow(Carbon::parse((string) getenv('HORUS_COTIZACIONES_FIXTURE_TIME')));
Artisan::call('migrate', ['--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create(['name' => 'Administrador ficticio']);
$admin->assignRole('admin');
$rol = $_SERVER['HTTP_X_HORUS_FIXTURE_ROLE'] ?? 'admin';
$actor = $admin;
if (in_array($rol, ['lector', 'sin_permiso'], true)) {
    $actor = User::factory()->create(['name' => 'Personal ficticio']);
    if ($rol === 'lector') {
        $actor->assignRole('admin');
        // Lector admin sin capacidades de escritura, solo en la base efímera del fixture.
        Role::findByName('admin')->revokePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
        $actor->givePermissionTo('cotizaciones.ver');
    } else {
        $actor->assignRole('coordinador');
        $actor->givePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
    }
}
auth()->setUser($actor);
$revision = filter_var($_SERVER['HTTP_X_HORUS_FIXTURE_REVISION'] ?? '1', FILTER_VALIDATE_INT);
if (! is_int($revision) || $revision < 1 || $revision > 20) {
    throw new RuntimeException('Invalid synthetic revision.');
}
$solicitud = new SolicitudCotizacion(['contacto_nombre' => 'Contacto ficticio', 'contacto_email' => 'persona@example.test',
    'necesidad_cliente' => str_repeat('Necesidad ficticia privada. ', 30)]);
$identificador = preg_match('#\A/admin/cotizaciones/([0-9a-f-]{36})#D', $path, $coincidencia)
    ? $coincidencia[1] : '11111111-1111-4111-8111-111111111111';
$solicitud->identificador = $identificador;
$solicitud->codigo = 'SOL-'.strtoupper($identificador);
$solicitud->recibida_at = now();
$solicitud->creado_por = $admin->id;
$solicitud->creador_ambito = $admin->id;
$solicitud->creacion_clave_hash = str_repeat('a', 64);
$solicitud->creacion_huella = str_repeat('b', 64);
$solicitud->estado = 'recibida';
$solicitud->revision = $revision;
$solicitud->save();
CotizacionHistorial::create(['solicitud_id' => $solicitud->id, 'revision' => 1, 'evento' => 'creada',
    'actor_id' => $admin->id, 'actor_ambito' => $admin->id, 'metadatos' => ['revision' => 1], 'created_at' => now()]);
$app['session']->driver()->start();
$app['session']->driver()->put('_token', 'cotizaciones-fixture-csrf-sintetico');
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-cotizaciones');
$response->headers->set('X-Horus-Fixture-Audits', (string) AuditLog::count());
$response->send();
$kernel->terminate($request, $response);
