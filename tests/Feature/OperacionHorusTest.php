<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Services\OperacionHorus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperacionHorusTest extends TestCase
{
    private function habilitar(): void
    {
        config(['horus-ops.enabled' => true, 'horus-advisor.enabled' => true,
            'horus-catalog.sync_enabled' => true, 'horus-advisor.token' => str_repeat('t', 32),
            'horus-advisor.signing_key' => str_repeat('k', 32), 'queue.default' => 'database',
            'cache.default' => 'array']);
    }

    public function test_estado_predeterminado_no_consulta_red_cola_o_cache(): void
    {
        Http::preventStrayRequests();
        Cache::shouldReceive('get')->never();
        $this->artisan('horus:advisor-operacion')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_ejecucion_desactivada_no_despacha_trabajos(): void
    {
        Queue::fake();
        $this->artisan('horus:advisor-operacion sync --execute')->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_configuracion_rechaza_destinos_remotos_sync_y_secretos_cortos_sin_exponerlos(): void
    {
        $this->habilitar();
        config(['horus-advisor.url' => 'http://example.invalid/?token=NO_DIVULGAR',
            'queue.default' => 'sync', 'horus-advisor.token' => 'NO_DIVULGAR']);
        $errors = app(OperacionHorus::class)->errores();
        $this->assertContains('advisor_url', $errors);
        $this->assertContains('service_token', $errors);
        $this->assertContains('async_queue', $errors);
        $this->assertStringNotContainsString('NO_DIVULGAR', json_encode($errors));
    }

    public function test_preflight_detecta_version_modelo_y_heartbeat_sin_mensajes(): void
    {
        $this->habilitar();
        $version = str_repeat('a', 40);
        Cache::put('horus-ai.catalog.expected-version', $version);
        Cache::put('horus.ops.worker', ['at' => time(), 'attempts' => 2, 'failures' => 0]);
        Http::fake(['*/health' => fn () => Http::response(['status' => 'ok']),
            '*/ready' => fn () => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $version]),
            '*/api/v1/info' => fn () => Http::response(['search_mode' => 'hybrid', 'catalog_version' => $version])]);
        $status = app(OperacionHorus::class)->estado(true, false);
        $this->assertTrue($status['ready']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/info')
            && $request->hasHeader('X-Horus-Service-Token', str_repeat('t', 32)));
        Cache::put('horus-ai.catalog.expected-version', str_repeat('b', 40));
        $this->assertFalse(app(OperacionHorus::class)->estado(true, false)['ready']);
    }

    public function test_token_incorrecto_impide_readiness_aunque_health_y_ready_sean_correctos(): void
    {
        $this->habilitar();
        $version = str_repeat('a', 40);
        Cache::put('horus-ai.catalog.expected-version', $version);
        Cache::put('horus.ops.worker', ['at' => time()]);
        Http::fake(['*/health' => Http::response(['status' => 'ok']),
            '*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $version]),
            '*/api/v1/info' => Http::response(['error' => 'unauthorized'], 401)]);
        $status = app(OperacionHorus::class)->estado(true, false);
        $this->assertFalse($status['ready']);
        $this->assertFalse($status['authenticated']);
    }

    public function test_fallo_http_se_redacta_y_heartbeat_obsoleto_no_es_ready(): void
    {
        $this->habilitar();
        Http::fake(['*' => Http::response(['detail' => 'NO_DIVULGAR'], 500)]);
        Cache::put('horus.ops.worker', ['at' => time() - 91]);
        $status = app(OperacionHorus::class)->estado(true, false);
        $this->assertFalse($status['ready']);
        $this->assertSame('stale', $status['worker']);
        $this->assertStringNotContainsString('NO_DIVULGAR', json_encode($status));
    }

    public function test_renovacion_reutiliza_el_job_existente_solo_con_ejecucion_explicita(): void
    {
        $this->habilitar();
        Queue::fake();
        $this->artisan('horus:advisor-operacion sync')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('horus:advisor-operacion sync --execute')->assertSuccessful();
        Queue::assertPushed(SincronizarCatalogoAdvisor::class, 1);
    }

    public function test_renovaciones_repetidas_se_agrupan(): void
    {
        $this->habilitar();
        Queue::fake();
        $this->artisan('horus:advisor-operacion sync --execute')->assertSuccessful();
        $this->artisan('horus:advisor-operacion sync --execute')->assertSuccessful();
        Queue::assertPushed(SincronizarCatalogoAdvisor::class, 1);
    }

    public function test_worker_simulado_publica_heartbeat_y_libera_estado_al_terminar(): void
    {
        $this->habilitar();
        $worker = \Mockery::mock(Worker::class);
        $worker->shouldReceive('setName')->andReturnSelf();
        $worker->shouldReceive('setCache')->andReturnSelf();
        $worker->shouldReceive('daemon')->once()->andReturnUsing(function ($connection, $queue, $options): int {
            $this->assertSame('database', $connection);
            $this->assertSame(45, $options->timeout);
            $this->assertSame(3600, $options->maxTime);
            Event::dispatch(new Looping('database', 'default'));
            $this->assertSame(time(), Cache::get('horus.ops.worker')['at']);
            $job = \Mockery::mock(Job::class);
            $job->shouldReceive('resolveName')->andReturn(SincronizarCatalogoAdvisor::class);
            $job->shouldReceive('getJobId')->andReturn('fixture');
            Event::dispatch(new JobProcessed('database', $job));
            $this->assertSame(1, Cache::get('horus.ops.worker')['attempts']);

            return 0;
        });
        $this->app->instance('queue.worker', $worker);
        $this->artisan('horus:advisor-operacion worker --execute')->assertSuccessful();
        $this->assertNull(Cache::get('horus.ops.worker'));
        $this->assertTrue(Cache::lock('horus.ops.worker.lock')->get());
    }

    public function test_scheduler_permanece_desactivado_por_defecto(): void
    {
        $events = app(Schedule::class)->events();
        $renewals = array_filter($events, fn ($event): bool => str_contains($event->command ?? '', 'advisor-operacion'));
        $this->assertCount(0, $renewals);
        config(['horus-ops.enabled' => true, 'horus-ops.renew_catalog' => true]);
        app(OperacionHorus::class)->programar();
        $renewals = array_values(array_filter(app(Schedule::class)->events(),
            fn ($event): bool => str_contains($event->command ?? '', 'advisor-operacion')));
        $this->assertCount(1, $renewals);
        Cache::put('horus.ops.worker', ['at' => time() - 91]);
        $this->assertFalse(array_values($renewals)[0]->filtersPass($this->app));
        Cache::put('horus.ops.worker', ['at' => time()]);
        $this->assertTrue(array_values($renewals)[0]->filtersPass($this->app));
    }

    public function test_fallo_de_despacho_libera_bloqueo_sin_divulgar_excepcion(): void
    {
        $this->habilitar();
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('NO_DIVULGAR'));
        });
        $this->artisan('horus:advisor-operacion sync --execute')->expectsOutput('operation_failed')->assertFailed();
        $this->assertTrue(Cache::lock('horus.ops.renew')->get());
    }
}
