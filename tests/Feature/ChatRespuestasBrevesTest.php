<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ConveniosPublicos;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Tests\TestCase;

class ChatRespuestasBrevesTest extends TestCase
{
    public function test_las_tarjetas_conservan_todos_los_datos_del_inicio_original(): void
    {
        $original = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/convenios-inicio-original.json'), true, 512, JSON_THROW_ON_ERROR);
        $tarjetas = app(ConveniosPublicos::class)->tarjetas();
        $this->assertEquals($original, $tarjetas);
        $response = $this->get('/')->assertOk();
        foreach ($tarjetas as $tarjeta) {
            $response->assertSee($tarjeta['nombre']);
        }
        $response->assertSee(Js::from($tarjetas)->toHtml(), false);
    }

    public function test_las_respuestas_reales_de_python_atraviesan_la_validacion_de_laravel(): void
    {
        $casos = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-breve-replies.json'), true, 512, JSON_THROW_ON_ERROR);
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        foreach ($casos as $caso) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->simular($caso['reply']);
            $resultado = app(HorusAdvisorClient::class)->orientar($caso['query']);
            $this->assertNotNull($resultado, $caso['query']);
            $this->assertSame($caso['reply']['answer'], $resultado['respuesta'], $caso['query']);
        }
    }

    public function test_la_respuesta_breve_no_se_acepta_con_otra_fuente_ni_con_datos_agregados(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        $casos = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-breve-replies.json'), true, 512, JSON_THROW_ON_ERROR);
        $original = $casos[0]['reply'];
        foreach (['fuente', 'precio', 'datos_privados'] as $alteracion) {
            $reply = $original;
            if ($alteracion === 'fuente') {
                $reply['sources'] = [['id' => 'servicio_soporte', 'title' => 'Soporte', 'url_path' => '/tecnologias/soporte-mantenimiento']];
            } else {
                $reply['answer'] .= $alteracion === 'precio' ? ' Cuesta S/ 150.' : ' Envíame tu DNI por aquí.';
            }
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->simular($reply);
            $this->assertNull(app(HorusAdvisorClient::class)->orientar('Qué convenios tienen'), $alteracion);
        }
    }

    public function test_resumen_y_atributos_del_catalogo_se_validan_con_el_snapshot_firmado(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-breve-catalogo.json'), true, 512, JSON_THROW_ON_ERROR);
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $contenido = ['managed_types' => ['curso', 'servicio'], 'items' => $fixture['items']];
        $firma = app(FirmaCatalogoAdvisor::class);
        $catalogo = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido($contenido),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(), ...$contenido];
        $catalogo['signature'] = $firma->firmar($catalogo);
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalogo);
        Cache::forever('horus-ai.catalog.expected-version', $catalogo['catalog_version']);
        foreach ($fixture['cases'] as $caso) {
            foreach ([false, true] as $alterar) {
                $reply = $caso['reply'];
                if ($alterar) {
                    $reply['answer'] .= ' Inicia el 2099-01-01 y cuesta S/ 150.';
                }
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded',
                    'auth' => 'configured', 'semantic' => 'disabled', 'catalog' => 'loaded',
                    'catalog_version' => $catalogo['catalog_version']]), '*/api/v1/chat' => Http::response($reply)]);
                $resultado = app(HorusAdvisorClient::class)->orientar($caso['query']);
                $this->assertSame(! $alterar, $resultado !== null, $caso['query']);
                if (! $alterar) {
                    $this->assertSame($reply['answer'], $resultado['respuesta']);
                }
            }
        }
    }

    /** @param array<string, mixed> $reply */
    private function simular(array $reply): void
    {
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded',
            'auth' => 'configured', 'semantic' => 'disabled', 'catalog' => 'none']),
            '*/api/v1/chat' => Http::response($reply)]);
    }
}
