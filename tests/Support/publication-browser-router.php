<?php

declare(strict_types=1);
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Fixture opt-in: solo servidor loopback, esquema y datos reconstruidos EN MEMORIA por petición.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_PUBLICATION_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
if (is_file($root.'/bootstrap/cache/config.php') || is_file($root.'/tests/Support/.publication-fixture-no-hot')) {
    throw new RuntimeException('Browser fixture refuses cached configuration or a Vite hot marker.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.rawurldecode(is_string($path) ? $path : '/'));
if ($asset !== false && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($asset)) {
    return false;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_URL' => 'http://127.0.0.1:8769', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
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
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.publication-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite'
    || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Browser fixture requires SQLite in memory.');
}
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create();
$admin->assignRole('admin');
auth()->login($admin);
$course = Curso::create([
    'codigo' => 'FIXTURE', 'nombre' => 'Nombre administrativo privado', 'descripcion' => 'Descripción administrativa privada',
    'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 9876.54, 'modalidad' => 'presencial',
    'horario' => 'Horario administrativo privado', 'cupos_maximos' => 2, 'cupos_disponibles' => 2, 'estado' => 'activo',
]);
CursoPublicacion::create([
    'curso_id' => $course->id, 'titulo' => 'Python inicial', 'resumen' => 'Resumen público de prueba.',
    'modalidad' => 'virtual', 'horario' => 'Martes de 19 a 21 horas', 'inscripcion' => null,
    'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addDay()->toDateString(),
    'publicada' => true, 'autorizado_por' => $admin->id, 'autorizado_at' => now(),
]);
$app->handleRequest(Request::capture());
