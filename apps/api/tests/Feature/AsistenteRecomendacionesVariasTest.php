<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Varias opciones publicadas (22/11/2026). Las respuestas salen de Tests/Fixtures/chat-recomendaciones-varias.json,
 * que genera el motor de Horus AI (su prueba test_recomendaciones_varias.py exige que siga produciéndolas).
 */
class AsistenteRecomendacionesVariasTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{items: list<array<string, mixed>>, cases: list<array{query: string, state: array<string, mixed>|null, reply: array<string, mixed>}>} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-recomendaciones-varias.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->catalogoFirmado($this->fixture['items']);
    }

    public function test_la_recomendacion_con_alternativas_llega_con_una_tarjeta_por_opcion(): void
    {
        $respuesta = $this->consultar($this->fixture['cases'][0]['reply']);

        $this->assertNotNull($respuesta);
        $this->assertSame('advisor', $respuesta['fuente']);
        $this->assertSame(['cat_curso_publicacion_1', 'cat_curso_publicacion_2', 'cat_curso_publicacion_3'],
            array_column($respuesta['recomendaciones'] ?? [], 'id'));
        $this->assertStringContainsString('También coinciden con lo que buscas', $respuesta['respuesta']);
        $this->assertSame(['/educacion/cursos'], $respuesta['enlaces'] ?? null);
    }

    public function test_el_seguimiento_sobre_dos_cursos_se_acepta_con_los_datos_de_sus_fichas(): void
    {
        $respuesta = $this->consultar($this->fixture['cases'][1]['reply']);

        $this->assertNotNull($respuesta, 'La duración consta en las fichas citadas: es evidencia válida.');
        $this->assertSame("«Excel desde cero»: dura 6 semanas.\n«Excel para finanzas»: dura 6 semanas.", $respuesta['respuesta']);
    }

    public function test_una_cifra_que_no_consta_en_las_fichas_citadas_se_rechaza(): void
    {
        $reply = $this->fixture['cases'][1]['reply'];
        $reply['answer'] = "«Excel desde cero»: dura 7 semanas.\n«Excel para finanzas»: dura 6 semanas.";

        $this->assertNull($this->consultar($reply));
    }

    public function test_una_ficha_citada_con_otro_titulo_no_aporta_evidencia(): void
    {
        $reply = $this->fixture['cases'][1]['reply'];
        $reply['sources'][0]['title'] = 'Título alterado';

        $this->assertNull($this->consultar($reply));
    }

    /** @param list<array<string, mixed>> $items */
    private function catalogoFirmado(array $items): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $firma = app(FirmaCatalogoAdvisor::class);
        $catalogo = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido(['managed_types' => ['curso', 'servicio'], 'items' => $items]),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'managed_types' => ['curso', 'servicio'], 'items' => $items];
        $catalogo['signature'] = $firma->firmar($catalogo);
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalogo);
        Cache::forever('horus-ai.catalog.expected-version', $catalogo['catalog_version']);
        $this->version = $catalogo['catalog_version'];
    }

    private string $version = '';

    /**
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>|null
     */
    private function consultar(array $reply): ?array
    {
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => 'loaded', 'catalog_version' => $this->version]),
            '*/api/v1/chat' => Http::response($reply)]);

        return app(HorusAdvisorClient::class)->orientar('consulta de prueba');
    }
}
