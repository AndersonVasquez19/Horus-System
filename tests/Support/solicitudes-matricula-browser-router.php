<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\SolicitudMatricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Exclusivo de http://127.0.0.1:8780, opt-in, SQLite :memory: nueva por petición.
// Los estados administrativos son sintéticos: no persiste información entre peticiones.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_SOLICITUDES_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8780'
    || (getenv('HORUS_PG_TEST_PROFILE') !== false && getenv('HORUS_PG_TEST_PROFILE') !== '')) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Fixture refuses cached configuration.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if (! is_string($path) || ! preg_match('#\A(?:/educacion/cursos|/solicitar-matricula|/admin/solicitudes-matricula(?:/[a-f0-9-]{36})?)\z#D', $path)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('q', 32)),
    'APP_URL' => 'http://127.0.0.1:8780', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MATRICULA_MAIL_ENABLED' => 'false', 'HORUS_ADVISOR_ENABLED' => 'false', 'HORUS_AI_CATALOG_SYNC_ENABLED' => 'false',
    'HORUS_OPS_ENABLED' => 'false', 'RECLAMACIONES_CORREO_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.solicitudes-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('horus-advisor.enabled') || config('chatbot.public_catalog_enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires memory SQLite and disabled AI/mail.');
}
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);
// La tabla de solicitudes está apartada en database/migraciones-suspendidas/; solo se instala en esta SQLite :memory:.
(require database_path('migraciones-suspendidas/2026_11_09_000035_create_solicitudes_matricula_table.php'))->up();
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create(['name' => 'Administrador ficticio']);
$admin->assignRole('admin');
$curso = Curso::create([
    'codigo' => 'SOL-FIXTURE', 'nombre' => 'Curso interno ficticio', 'descripcion' => 'Contenido privado ficticio',
    'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Interno',
    'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
]);
CursoPublicacion::create([
    'curso_id' => $curso->id, 'titulo' => 'Curso público de redes', 'resumen' => 'Ficha editorial ficticia de redes.', 'modalidad' => 'virtual',
    'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
    'publicada' => true, 'autorizado_por' => $admin->id, 'autorizado_at' => now(),
]);
if (str_starts_with($path, '/admin/')) {
    auth()->setUser($admin);
    $solicitud = SolicitudMatricula::create([
        'identificador' => '11111111-1111-4111-8111-111111111111', 'huella' => str_repeat('a', 64),
        'curso_titulo' => 'Curso público de redes', 'modalidad' => 'virtual', 'nombres' => 'Persona', 'apellidos' => 'Ficticia',
        'email' => 'persona@example.test', 'telefono' => '+51 999 111 222', 'mensaje' => 'Consulta ficticia para revisión.', 'consentimiento_at' => now(),
    ]);
    if (($_SERVER['HTTP_X_HORUS_FIXTURE_STATE'] ?? '') === 'en_revision') {
        $solicitud->estado = 'en_revision';
        $solicitud->revision = 2;
        $solicitud->save();
    }
}
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-solicitudes');
$response->headers->set('X-Horus-Fixture-Count', (string) SolicitudMatricula::count());
$response->headers->set('X-Horus-Fixture-State', SolicitudMatricula::first()?->estado ?? 'sin_solicitud');
$response->headers->set('X-Horus-Fixture-Enrollments', (string) DB::table('matriculas')->count());
$response->send();
$kernel->terminate($request, $response);
