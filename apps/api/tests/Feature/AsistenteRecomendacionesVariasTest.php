<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
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
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturnUsing(function () use ($catalogo): array {
            $this->construcciones++;

            return $catalogo;
        });
        Cache::forever('horus-ai.catalog.expected-version', $catalogo['catalog_version']);
        $this->version = $catalogo['catalog_version'];
    }

    private string $version = '';

    /** Veces que se construyó el catálogo firmado (consulta, permisos, privacidad y firma). */
    private int $construcciones = 0;

    public function test_el_catalogo_se_construye_una_vez_por_mensaje_y_de_nuevo_en_el_siguiente(): void
    {
        $unaOpcion = $this->fixture['cases'][0]['reply'];
        $unaOpcion['needs_clarification'] = false;
        $unaOpcion['clarification_question'] = null;
        $unaOpcion['sources'] = array_slice($unaOpcion['sources'], 0, 1);
        $unaOpcion['recommendations'] = array_slice($unaOpcion['recommendations'], 0, 1);
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => 'loaded', 'catalog_version' => $this->version]),
            '*/api/v1/chat' => Http::response($unaOpcion)]);
        $servicio = app(AsistenteVirtualService::class);

        $this->assertSame('advisor', $servicio->procesarMensaje('Quiero aprender Excel desde cero', 'memo')['fuente']);
        $this->assertSame(1, $this->construcciones);
        // «sí» usa el catálogo dos veces (título del curso en foco y validación de la respuesta): una sola construcción.
        $this->assertSame('advisor', $servicio->procesarMensaje('sí', 'memo')['fuente']);
        Http::assertSent(static fn ($r): bool => str_ends_with($r->url(), '/api/v1/chat') && $r['message'] === 'Información sobre Excel desde cero');
        $this->assertSame(2, $this->construcciones);
        // El mensaje siguiente vuelve a construirlo: una ficha retirada no se sigue citando.
        $servicio->procesarMensaje('¿y el horario?', 'memo');
        $this->assertSame(3, $this->construcciones);
    }

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
