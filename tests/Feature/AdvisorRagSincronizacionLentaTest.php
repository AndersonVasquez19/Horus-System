<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdvisorRagSincronizacionLentaTest extends TestCase
{
    public function test_timeout_is_reconciled_without_reposting_or_confirming_a_pending_catalog(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true,
            'horus-advisor.url' => 'http://127.0.0.1:8001', 'horus-advisor.token' => str_repeat('t', 32),
            'horus-advisor.signing_key' => str_repeat('k', 32)]);
        $catalog = ['schema_version' => '1.0', 'catalog_version' => str_repeat('a', 40),
            'source_system' => 'horus-laravel', 'generated_at' => now('UTC')->toIso8601String(),
            'expires_at' => now('UTC')->addMinutes(5)->toIso8601String(),
            'managed_types' => ['curso', 'servicio'], 'items' => []];
        $catalog['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($catalog);
        $version = $catalog['catalog_version'];
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v1/catalog/sync' => Http::failedConnection(),
            '*/ready' => Http::sequence()->push([
                'status' => 'not_ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'catalog' => 'stale', 'catalog_pending_version' => $version,
            ])->push([
                'status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'catalog' => 'loaded', 'catalog_version' => $version,
            ]),
        ]);
        $exporter = $this->createMock(ExportadorCatalogoAdvisor::class);
        $exporter->expects($this->once())->method('construirParaSincronizacion')->willReturn($catalog);
        $queued = $this->createMock(Job::class);
        $queued->expects($this->once())->method('release')->with(5);
        $job = new SincronizarCatalogoAdvisor;
        $job->setJob($queued);
        $client = app(HorusAdvisorClient::class);

        $job->handle($exporter, $client);
        $this->assertNull(Cache::get('horus-ai.catalog.expected-version'));
        $job->handle($exporter, $client);

        $this->assertSame($version, Cache::get('horus-ai.catalog.expected-version'));
        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/api/v1/catalog/sync')));
    }

    public function test_unrelated_pending_version_cannot_confirm_synchronization(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true,
            'horus-advisor.url' => 'http://127.0.0.1:8001', 'horus-advisor.token' => str_repeat('t', 32)]);
        Http::preventStrayRequests();
        Http::fake(['*/ready' => Http::response(['status' => 'not_ready', 'knowledge' => 'loaded',
            'auth' => 'configured', 'catalog' => 'stale', 'catalog_pending_version' => str_repeat('b', 40)])]);
        $this->assertNull(app(HorusAdvisorClient::class)->estadoSincronizacionCatalogo(str_repeat('a', 40)));
    }

    public function test_sync_queue_cannot_silently_acknowledge_a_build_that_needs_deferred_confirmation(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true]);
        $exporter = $this->createMock(ExportadorCatalogoAdvisor::class);
        $exporter->method('construirParaSincronizacion')->willReturn([]);
        $client = $this->createMock(HorusAdvisorClient::class);
        $client->method('sincronizarCatalogo')->willReturn(['accepted' => true, 'applied' => false,
            'catalog' => 'pending', 'catalog_version' => str_repeat('a', 40)]);
        $queued = $this->createMock(SyncJob::class);
        $queued->expects($this->never())->method('release');
        $job = new SincronizarCatalogoAdvisor;
        $job->setJob($queued);
        $this->expectException(\RuntimeException::class);
        try {
            $job->handle($exporter, $client);
        } finally {
            $this->assertNull(Cache::get('horus-ai.catalog.expected-version'));
        }
    }
}
