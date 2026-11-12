<?php

declare(strict_types=1);

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use App\Services\ExportadorCatalogoAdvisor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('HORUS_CONSOLIDACION_FIXTURE') !== '1') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.consolidacion-inexistente');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Exclusivamente SQLite :memory:.');
}
$manifiesto = json_decode(file_get_contents(base_path('docs/consolidacion-fase-4-manifiesto.json')), true, 512, JSON_THROW_ON_ERROR);
$paths = [];
foreach ($manifiesto['migrations'] as $migracion) {
    if (preg_match('/_00002[23]_/', $migracion['file'])) {
        continue;
    }
    $path = base_path($migracion['file']);
    if (! hash_equals($migracion['sha256'], hash_file('sha256', $path))) {
        throw new RuntimeException('Migración distinta del manifiesto revisado.');
    }
    $paths[] = $path;
}
Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
(new RolesAndPermissionsSeeder)->run();
$check = static function (bool $ok, string $label): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo 'OK '.$label.PHP_EOL;
};
$actor = User::factory()->create();
$actor->assignRole('admin');
$course = Curso::create(['codigo' => 'CONSOLIDACION-AI', 'nombre' => 'Curso interno sintético',
    'descripcion' => 'Descripción interna sintética.', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
    'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Horario sintético', 'cupos_maximos' => 5,
    'cupos_disponibles' => 5, 'estado' => 'activo']);
$publication = CursoPublicacion::create(['curso_id' => $course->id, 'titulo' => 'Análisis de datos con Excel',
    'resumen' => 'Capacitación en análisis de datos y hojas de cálculo para el trabajo.',
    'modalidad' => 'virtual', 'inscripcion' => 'Consulta los canales oficiales de inscripción.',
    'vigente_desde' => now()->subDay()->toDateString(), 'vigente_hasta' => now()->addDay()->toDateString(),
    'publicada' => true, 'autorizado_por' => $actor->id, 'autorizado_at' => now()]);
$technology = ServicioTecnologico::forceCreate(['codigo' => 'CONSOLIDACION-SERVICIO',
    'identificador_publico' => (string) Str::uuid(), 'nombre' => 'Servicio interno sintético',
    'estado' => 'activo', 'revision' => 1]);
$servicePublication = ServicioPublicacion::forceCreate(['servicio_tecnologico_id' => $technology->id,
    'titulo' => 'Soporte técnico de computadoras', 'resumen' => 'Servicio de soporte y mantenimiento de equipos informáticos.',
    'descripcion' => 'Diagnóstico y mantenimiento de computadoras para oficinas.', 'estado' => 'publicada',
    'vigente_desde' => now()->subDay()->toDateString(), 'vigente_hasta' => now()->addDay()->toDateString(),
    'autorizado_por' => $actor->id, 'autorizado_at' => now()]);
config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
    'horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true]);
$httpCount = 0;
$url = parse_url((string) config('horus-advisor.url'));
Http::globalRequestMiddleware(static function ($request) use (&$httpCount, $url) {
    if ($request->getUri()->getHost() !== '127.0.0.1' || $request->getUri()->getPort() !== $url['port']) {
        throw new RuntimeException('El smoke solo permite su FastAPI temporal.');
    }
    $httpCount++;

    return $request;
});
$snapshot = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
$check(count($snapshot['items']) === 2, 'exportador_real_curso_y_servicio_publicados');
$check(! str_contains(json_encode($snapshot), 'Curso interno sintético'), 'sin_datos_administrativos');
$work = static function () use ($check): void {
    Queue::connection('database')->push(new SincronizarCatalogoAdvisor);
    $deadline = microtime(true) + 45;
    do {
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 60]);
        $check($exit === 0, 'worker_database_ejecucion');
        if (DB::table('jobs')->count() === 0) {
            break;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    $check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'cola_completada_sin_fallos');
};
$work();
$check(Cache::get('horus-ai.catalog.expected-version') === $snapshot['catalog_version'], 'version_firmada_confirmada');
$service = app(AsistenteVirtualService::class);
$reply = $service->procesarMensaje('¿Tienen capacitaciones relacionadas con análisis de datos?', 'consolidacion-publico');
$check($reply['fuente'] === 'advisor', 'laravel_fastapi_minilm_real');
$check(count($reply['recomendaciones'] ?? []) > 0, 'tarjeta_de_recomendacion');
$before = DB::table('chat_logs')->count();
$httpBefore = $httpCount;
$private = $service->procesarMensaje('Mi DNI es 12345678', 'consolidacion-privacidad');
$check($private['fuente'] === 'respaldo' && DB::table('chat_logs')->count() === $before + 1, 'privacidad_con_auditoria_redactada');
$check($httpCount === $httpBefore, 'privacidad_cero_http');
$check(DB::table('chat_logs')->where('mensaje_usuario', '<>', '[contenido omitido por privacidad]')->count() === 0, 'sin_mensajes_privados_guardados');
$publication->update(['publicada' => false]);
$servicePublication->estado = 'retirada';
$servicePublication->save();
$work();
$actual = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
$check($actual['items'] === [] && Cache::get('horus-ai.catalog.expected-version') === $actual['catalog_version'], 'retirada_editorial_sincronizada');
config(['horus-advisor.enabled' => false]);
$check($service->procesarMensaje('cursos', 'consolidacion-apagado')['fuente'] === 'respaldo', 'contingencia_deshabilitado');
echo "FINAL integración real con cola database, exportador sin dobles y SQLite en memoria.\n";
echo "CONSOLIDACION_OK\n";
