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

class AdvisorRagFragmentosTest extends TestCase
{
    public function test_accepts_only_exact_fragments_from_the_current_signed_public_document(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        Http::preventStrayRequests();
        $item = ['stable_id' => 'curso:rag-redes', 'type' => 'curso', 'publication_status' => 'vigente',
            'title' => 'Administracion de redes', 'summary' => 'Formacion publica sobre redes empresariales.',
            'details' => 'El laboratorio permite configurar segmentacion VLAN y diagnosticar enlaces.',
            'updated_at' => '2026-11-04T10:00:00-05:00'];
        $content = ['managed_types' => ['curso', 'servicio'], 'items' => [$item]];
        $catalog = ['schema_version' => '1.0', 'catalog_version' => app(FirmaCatalogoAdvisor::class)->versionContenido($content),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(), ...$content];
        $catalog['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($catalog);
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalog);
        Cache::forever('horus-ai.catalog.expected-version', $catalog['catalog_version']);
        $answer = $item['title'].': '.$item['details'].' Fuente: catálogo publicado por Horus Group actualizado el 2026-11-04).';
        foreach ([$answer => true, $answer.' Precio 800.' => false,
            $item['title'].': segmentacion VLAN Fuente: catálogo publicado por Horus Group actualizado el 2026-11-04).' => false] as $text => $valid) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $catalog['catalog_version']]),
                '*/api/v1/chat' => Http::response(['answer' => $text, 'intent' => 'training_info', 'mode' => 'hybrid',
                    'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false,
                    'sources' => [['id' => 'cat_curso_rag_redes', 'title' => $item['title'], 'url_path' => null]],
                    'recommendations' => [['id' => 'cat_curso_rag_redes', 'title' => $item['title'], 'url_path' => null,
                        'kind' => 'course', 'reason' => 'Coincide con el contenido público de la consulta actual.',
                        'evidence' => ['cat_curso_rag_redes'], 'match_level' => 'alta']]])]);
            $reply = app(HorusAdvisorClient::class)->orientar('Informacion sobre redes');
            $this->assertSame($valid, $reply !== null);
        }
    }
}
