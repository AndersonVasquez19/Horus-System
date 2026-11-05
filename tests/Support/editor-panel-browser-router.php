<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\CursoPublicacion;
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

// Fixture opt-in exclusiva de http://127.0.0.1:8771. Reconstruye SQLite :memory: por petición.
// Las cabeceras Fixture simulan otro estado editorial; no persiste datos entre peticiones.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_EDITOR_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8771'
    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T[0-9:.]+Z\z/D', (string) getenv('HORUS_EDITOR_FIXTURE_TIME'))
    || (getenv('HORUS_PG_TEST_PROFILE') !== false && getenv('HORUS_PG_TEST_PROFILE') !== '')) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php') || is_file($root.'/tests/Support/.editor-fixture-no-hot')) {
    throw new RuntimeException('Fixture refuses cached configuration or an unexpected hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
if (! in_array($path, ['/admin/cursos', '/admin/cursos/1/publicacion'], true)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_URL' => 'http://127.0.0.1:8771', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'OPENAI_API_KEY' => '', 'MATRICULA_MAIL_ENABLED' => 'false',
    'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.editor-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('catalogo-publico.enabled') || config('horus-advisor.enabled') || config('chatbot.public_catalog_enabled')
    || config('registro-verificacion.enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires in-memory SQLite and disabled public/AI flags.');
}
Http::preventStrayRequests();
Carbon::setTestNow(Carbon::parse((string) getenv('HORUS_EDITOR_FIXTURE_TIME')));
Artisan::call('migrate', ['--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create(['name' => 'Administrador ficticio']);
$admin->assignRole('admin');
$role = ($_SERVER['HTTP_X_HORUS_FIXTURE_ROLE'] ?? '') === 'coordinador' ? 'coordinador' : 'admin';
$usuario = $role === 'admin' ? $admin : User::factory()->create(['name' => 'Personal ficticio']);
if ($role !== 'admin') {
    $usuario->assignRole($role);
}
// Usuario ficticio para esta petición sin regenerar la sesión antes de StartSession.
auth()->setUser($usuario);
$curso = Curso::create([
    'codigo' => 'FIXTURE', 'nombre' => 'Curso administrativo ficticio', 'descripcion' => 'Descripción interna ficticia',
    'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Interno',
    'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
]);
CursoPublicacion::create([
    'curso_id' => $curso->id, 'titulo' => ($_SERVER['HTTP_X_HORUS_FIXTURE_REVISION'] ?? '') === 'changed' ? 'Edición de otro administrador' : 'Redes públicas',
    'resumen' => 'Contenido editorial ficticio y autorizado.', 'modalidad' => 'virtual', 'horario' => null, 'inscripcion' => null,
    'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addDay()->toDateString(), 'publicada' => true,
    'autorizado_por' => $admin->id, 'autorizado_at' => now(),
]);
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
// Evidencia del resultado dentro de ESTA petición en memoria, no una promesa de persistencia.
$ficha = CursoPublicacion::firstOrFail();
$response->headers->set('X-Horus-Fixture-Title', rawurlencode($ficha->titulo));
$response->headers->set('X-Horus-Fixture-Published', $ficha->publicada ? '1' : '0');
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-editor');
$response->send();
$kernel->terminate($request, $response);
