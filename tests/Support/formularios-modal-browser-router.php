<?php

declare(strict_types=1);

use App\Models\AprobacionInterna;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Solo http://127.0.0.1:8778, opt-in y SQLite :memory: reconstruida por petición.
// No usar este router en la aplicación real ni atribuir persistencia al fixture.
if (PHP_SAPI !== 'cli-server' || getenv('HORUS_MODAL_BROWSER_FIXTURE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:8778'
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
if (! is_string($path) || ! preg_match('#\A/admin(?:/matriculas/opciones/(?:estudiantes|cursos)|/(?:estudiantes|cursos|matriculas|usuarios)(?:/create|/[1-9][0-9]*(?:/edit|/publicacion)?)?|/roles(?:/[1-9][0-9]*)?|/aprobaciones|/configuracion|/servicios|/cotizaciones)?\z#D', $path)) {
    http_response_code(404);
    exit;
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('m', 32)),
    'APP_URL' => 'http://127.0.0.1:8778', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'SESSION_DRIVER' => 'cookie', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MATRICULA_MAIL_ENABLED' => 'false', 'HORUS_ADVISOR_ENABLED' => 'false', 'HORUS_AI_CATALOG_SYNC_ENABLED' => 'false',
    'HORUS_OPS_ENABLED' => 'false', 'RECLAMACIONES_CORREO_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'stderr',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile($root.'/tests/Support/.modal-fixture-no-hot');
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:'
    || config('horus-advisor.enabled') || config('chatbot.public_catalog_enabled') || config('matricula-mail.enabled')) {
    throw new RuntimeException('Fixture requires memory SQLite and disabled AI/mail.');
}
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$admin = User::factory()->create(['name' => 'Administrador ficticio']);
$admin->assignRole('admin');
auth()->setUser($admin);
$estudiante = Estudiante::create(['dni' => '70123456', 'nombres' => 'Estudiante', 'apellidos' => 'Ficticio', 'estado' => 'activo']);
$curso = Curso::create([
    'codigo' => 'FIXTURE', 'nombre' => 'Curso ficticio', 'descripcion' => 'Descripción ficticia', 'categoria' => 'tecnologia',
    'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Lunes 18:00', 'cupos_maximos' => 20, 'cupos_disponibles' => 19, 'estado' => 'activo',
]);
Matricula::create(['curso_id' => $curso->id, 'estudiante_id' => $estudiante->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pagada', 'monto_pagado' => 150]);
AprobacionInterna::create(['titulo' => 'Solicitud ficticia', 'tipo' => 'compra', 'estado' => 'pendiente', 'solicitante_id' => $admin->id]);
$solicitante = User::factory()->create(['name' => 'Solicitante ficticio']);
AprobacionInterna::create(['titulo' => 'Solicitud de otra persona', 'tipo' => 'compra', 'estado' => 'pendiente', 'solicitante_id' => $solicitante->id]);
AprobacionInterna::create(['titulo' => 'Solicitud resuelta ficticia', 'tipo' => 'compra', 'estado' => 'aprobado', 'solicitante_id' => $solicitante->id, 'aprobador_id' => $admin->id]);
$request = Request::capture();
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Horus-Fixture', 'sqlite-memory-modal');
$response->send();
$kernel->terminate($request, $response);
