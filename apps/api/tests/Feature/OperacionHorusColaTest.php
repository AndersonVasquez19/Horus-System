<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OperacionHorus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperacionHorusColaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conteos_solo_se_obtienen_al_solicitarlos_sin_exportar_payloads(): void
    {
        config(['horus-ops.enabled' => true, 'horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true,
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
        $version = str_repeat('a', 40);
        Cache::put('horus-ai.catalog.expected-version', $version);
        Cache::put('horus.ops.worker', ['at' => time()]);
        Http::fake(['*/health' => fn () => Http::response(['status' => 'ok']), '*/ready' => fn () => Http::response([
            'status' => 'ready', 'knowledge' => 'loaded', 'semantic' => 'loaded', 'auth' => 'configured',
            'catalog' => 'loaded', 'catalog_version' => $version]),
            '*/api/v1/info' => fn () => Http::response(['search_mode' => 'hybrid', 'catalog_version' => $version])]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'NO_DIVULGAR', 'attempts' => 0,
            'available_at' => time(), 'reserved_at' => null, 'created_at' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => 'operacion-ficticia', 'connection' => 'database', 'queue' => 'default',
            'payload' => 'SincronizarCatalogoAdvisor', 'exception' => 'NO_DIVULGAR', 'failed_at' => now()]);
        $ops = app(OperacionHorus::class);
        $this->assertArrayNotHasKey('pending_queue_total', $ops->estado(true, false));
        $status = $ops->estado(true, true);
        $this->assertSame([], $status['errors']);
        $this->assertSame('completed', $status['probe']);
        $this->assertSame(1, $status['pending_queue_total']);
        $this->assertSame(1, $status['failed_catalog_jobs']);
        $this->assertStringNotContainsString('NO_DIVULGAR', json_encode($status));
    }
}
