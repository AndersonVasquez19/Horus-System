<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdvisorFuentesCatalogoTest extends TestCase
{
    private function preparar(?\Closure $cambiar = null): array
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        Http::preventStrayRequests();
        $items = [['stable_id' => 'curso:publicacion-1', 'type' => 'curso', 'publication_status' => 'vigente',
            'title' => 'Redes digitales aplicadas', 'summary' => 'Practicas de laboratorio en redes.',
            'modalities' => ['virtual'], 'updated_at' => '2026-11-04T10:00:00-05:00']];
        $catalog = ['schema_version' => '1.0', 'catalog_version' => app(FirmaCatalogoAdvisor::class)->versionContenido(['managed_types' => ['curso', 'servicio'], 'items' => $items]),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'managed_types' => ['curso', 'servicio'], 'items' => $items];
        $catalog['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($catalog);
        if ($cambiar !== null) {
            $catalog = $cambiar($catalog);
        }
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalog);
        Cache::forever('horus-ai.catalog.expected-version', $catalog['catalog_version']);

        return $catalog;
    }

    private function consultar(array $catalog, string $answer, string $id = 'cat_curso_publicacion_1', string $title = 'Redes digitales aplicadas'): ?array
    {
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $catalog['catalog_version']]),
            '*/api/v1/chat' => Http::response(['answer' => $answer, 'intent' => 'training_info', 'mode' => 'hybrid',
                'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false,
                'sources' => [['id' => $id, 'title' => $title, 'url_path' => null]], 'recommendations' => []])]);

        return app(HorusAdvisorClient::class)->orientar('Informacion sobre redes digitales aplicadas');
    }

    private function texto(): string
    {
        return 'Redes digitales aplicadas: Practicas de laboratorio en redes. Modalidad publicada: virtual. Fuente: catálogo publicado por Horus Group actualizado el 2026-11-04).';
    }

    public function test_accepts_exact_public_answer_from_current_expected_catalog(): void
    {
        $catalog = $this->preparar();
        $this->assertSame('advisor', $this->consultar($catalog, $this->texto())['fuente'] ?? null);
    }

    public function test_rejects_unknown_catalog_reference_and_modified_answer(): void
    {
        $catalog = $this->preparar();
        $this->assertNull($this->consultar($catalog, $this->texto(), 'cat_curso_inexistente'));
        $this->assertNull($this->consultar($catalog, $this->texto().' Precio inventado 900.'));
        $this->assertNull($this->consultar($catalog, $this->texto(), 'cat_curso_publicacion_1', 'Titulo ajeno'));
    }

    public function test_rejects_catalog_if_expected_version_changes(): void
    {
        $catalog = $this->preparar();
        Cache::forever('horus-ai.catalog.expected-version', str_repeat('a', 40));
        $this->assertNull($this->consultar($catalog, $this->texto()));
        Http::assertSentCount(1);
    }

    public function test_rejects_invalid_signature(): void
    {
        $catalog = $this->preparar(function (array $catalog): array {
            $catalog['signature'] = 'hmac-sha256:'.str_repeat('0', 64);

            return $catalog;
        });
        $this->assertNull($this->consultar($catalog, $this->texto()));
    }

    public function test_rejects_retired_source_absent_from_current_snapshot(): void
    {
        $catalog = $this->preparar(function (array $catalog): array {
            $catalog['items'] = [];
            $catalog['catalog_version'] = app(FirmaCatalogoAdvisor::class)->versionContenido(['managed_types' => $catalog['managed_types'], 'items' => []]);
            $catalog['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($catalog);

            return $catalog;
        });
        $this->assertNull($this->consultar($catalog, $this->texto()));
    }

    public function test_catalog_reference_does_not_exempt_personal_input(): void
    {
        $this->preparar();
        $this->assertNull(app(HorusAdvisorClient::class)->orientar('Mi correo es visitante-ficticio@example.invalid'));
        Http::assertNothingSent();
    }
}
