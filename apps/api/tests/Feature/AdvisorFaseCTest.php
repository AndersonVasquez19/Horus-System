<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Contrato Python real congelado sobre contenido ficticio; transporte HTTP simulado. */
class AdvisorFaseCTest extends TestCase
{
    public function test_phase_c_public_replies_survive_the_existing_laravel_boundary(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/phase-c-public-replies.json')), true, 512, JSON_THROW_ON_ERROR);
        $content = ['managed_types' => ['curso', 'servicio'], 'items' => $fixture['items']];
        $firma = app(FirmaCatalogoAdvisor::class);
        $catalog = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido($content),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(), ...$content];
        $catalog['signature'] = $firma->firmar($catalog);
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalog);
        Cache::forever('horus-ai.catalog.expected-version', $catalog['catalog_version']);
        foreach ($fixture['cases'] as $case) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $catalog['catalog_version']]),
                '*/api/v1/chat' => Http::response($case['reply'])]);
            $client = app(HorusAdvisorClient::class);
            $reply = $client->orientar($case['query']);
            $this->assertNotNull($reply, $case['query'].' / '.($client->motivoContingencia() ?? ''));
            $this->assertSame('advisor', $reply['fuente']);
            $expected = $case['reply']['answer'];
            if ($case['reply']['needs_clarification']) {
                $expected .= "\n\n".$case['reply']['clarification_question'];
            }
            $this->assertSame($expected, $reply['respuesta']);
        }
    }
}
