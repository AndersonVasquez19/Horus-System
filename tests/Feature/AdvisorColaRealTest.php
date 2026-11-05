<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdvisorColaRealTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmacion_pendiente_sobrevive_al_payload_original_de_la_cola(): void
    {
        Http::preventStrayRequests();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
            'horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true]);
        $version = str_repeat('a', 40);
        $exportador = $this->createMock(ExportadorCatalogoAdvisor::class);
        $exportador->expects($this->once())->method('construirParaSincronizacion')->willReturn([]);
        $cliente = $this->createMock(HorusAdvisorClient::class);
        $cliente->expects($this->once())->method('sincronizarCatalogo')->willReturn([
            'accepted' => true, 'applied' => false, 'catalog' => 'pending', 'catalog_version' => $version,
        ]);
        $cliente->expects($this->once())->method('estadoSincronizacionCatalogo')->with($version)->willReturn([
            'accepted' => true, 'applied' => true, 'catalog' => 'loaded', 'catalog_version' => $version,
        ]);
        $this->app->instance(ExportadorCatalogoAdvisor::class, $exportador);
        $this->app->instance(HorusAdvisorClient::class, $cliente);
        Queue::connection('database')->push(new SincronizarCatalogoAdvisor);
        $payload = DB::table('jobs')->value('payload');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->trabajar();
        $this->assertSame($payload, DB::table('jobs')->value('payload'));
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
        $this->assertNull(Cache::get('horus-ai.catalog.expected-version'));
        // Solo adelanta la disponibilidad de la cola SQLite aislada; no cambia el job.
        DB::table('jobs')->update(['available_at' => time() - 1]);
        $this->trabajar();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame($version, Cache::get('horus-ai.catalog.expected-version'));
    }

    private function trabajar(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database',
            '--once' => true, '--sleep' => 0, '--tries' => 60]));
    }
}
