<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatEstadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
    }

    private function ready(array $changes = []): array
    {
        return array_replace(['status' => 'ready', 'knowledge' => 'loaded', 'semantic' => 'loaded',
            'auth' => 'configured', 'catalog' => 'none'], $changes);
    }

    public function test_solo_expone_un_estado_y_autentica_la_disponibilidad_sin_conversar(): void
    {
        Http::fake(['*/ready' => Http::response($this->ready()),
            '*/api/v1/info' => Http::response(['search_mode' => 'hybrid'])]);
        $this->getJson(route('chat.estado'))->assertOk()->assertExactJson(['estado' => 'listo'])
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/info')
            && $r->hasHeader('X-Horus-Service-Token', str_repeat('t', 32)));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertDatabaseCount('chat_logs', 0);
    }

    public static function estadosNoListos(): iterable
    {
        yield 'carga aunque ready diga ready' => [['semantic' => 'loading'], 'cargando'];
        yield 'carga con snapshot vencido' => [['status' => 'not_ready', 'semantic' => 'loading', 'catalog' => 'stale'], 'cargando'];
        yield 'snapshot vencido tras carga' => [['status' => 'not_ready', 'catalog' => 'stale'], 'no_disponible'];
        yield 'modelo roto' => [['semantic' => 'unavailable'], 'no_disponible'];
        yield 'modelo desactivado' => [['semantic' => 'disabled'], 'no_disponible'];
        yield 'modelo desconocido' => [['semantic' => 'arbitrario'], 'no_disponible'];
        yield 'conocimiento ausente' => [['knowledge' => 'unavailable'], 'no_disponible'];
        yield 'sin autenticacion' => [['auth' => 'missing'], 'no_disponible'];
        yield 'catalogo erroneo durante carga' => [['semantic' => 'loading', 'catalog' => 'error'], 'no_disponible'];
        yield 'estado desconocido' => [['status' => 'arbitrario'], 'no_disponible'];
    }

    #[DataProvider('estadosNoListos')]
    public function test_no_confunde_un_arranque_o_un_fallo_con_disponibilidad(array $changes, string $estado): void
    {
        Http::fake(['*/ready' => Http::response($this->ready($changes))]);
        $this->getJson(route('chat.estado'))->assertOk()->assertExactJson(['estado' => $estado]);
        Http::assertSentCount(1);
    }

    public function test_sincronizacion_exige_la_version_confirmada(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        $version = str_repeat('a', 40);
        Cache::put(config('horus-catalog.expected_version_cache_key'), $version);
        Http::fake(['*/ready' => Http::response($this->ready(['catalog' => 'loaded', 'catalog_version' => $version])),
            '*/api/v1/info' => Http::response(['search_mode' => 'hybrid', 'catalog_version' => $version])]);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'listo']);
        Cache::put(config('horus-catalog.expected_version_cache_key'), str_repeat('b', 40));
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
        Http::assertSentCount(3);
    }

    public function test_no_acepta_un_catalogo_ausente_si_la_sincronizacion_esta_activa(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        Http::fake(['*/ready' => Http::response($this->ready())]);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
    }

    public function test_una_credencial_rechazada_no_anuncia_listo(): void
    {
        Http::fake(['*/ready' => Http::response($this->ready()), '*/api/v1/info' => Http::response([], 401)]);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
    }

    public function test_desactivado_y_configuraciones_inseguras_no_hacen_http(): void
    {
        config(['horus-advisor.enabled' => false]);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'https://external.invalid']);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
        config(['horus-advisor.url' => 'http://127.0.0.1:8001', 'horus-advisor.token' => '']);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
        Http::assertNothingSent();
    }

    public function test_errores_y_cuerpos_arbitrarios_no_se_publican(): void
    {
        Http::fake(['*/ready' => Http::response(['error' => 'detalle-privado', 'token' => 'secreto-ficticio'], 500)]);
        $this->getJson(route('chat.estado'))->assertExactJson(['estado' => 'no_disponible']);
    }

    public function test_la_ruta_tiene_un_limite_independiente_de_los_mensajes(): void
    {
        config(['horus-advisor.enabled' => false]);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('chat.estado'))->assertOk();
        }
        $this->getJson(route('chat.estado'))->assertStatus(429);
        $this->postJson(route('chat.mensaje'), ['mensaje' => 'Hola', 'session_id' => 'limite-independiente'])
            ->assertOk();
        $route = app('router')->getRoutes()->getByName('chat.mensaje');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    public function test_seguimiento_distingue_objetivo_sin_reutilizar_texto_privado(): void
    {
        $client = app(HorusAdvisorClient::class);
        $focus = ['servicio_camaras'];
        $title = 'Cámaras de seguridad y videovigilancia';
        $this->assertSame('Dame detalles de '.$title, $client->resolverSeguimiento('características', $focus));
        $this->assertSame('Información sobre instalación y servicio de '.$title, $client->resolverSeguimiento('instalación', $focus));
        $this->assertSame('Busco un curso de '.$title, $client->resolverSeguimiento('capacitación', $focus));
        $this->assertSame('capacitación', $client->resolverSeguimiento('capacitación', []));
        $this->assertSame('instalación', $client->resolverSeguimiento('instalación', ['servicio_camaras', 'servicio_soporte']));
        $this->assertSame('quiero redes', $client->resolverSeguimiento('quiero redes', $focus));
        $this->assertSame('curso', $client->resolverSeguimiento('curso', ['faq_medios_pago']));
    }

    public function test_aclaracion_real_de_python_y_objetivos_pasan_los_validadores_existentes(): void
    {
        config(['tecnologias.visible' => true]);
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-aclaraciones-replies.json'), true, 512, JSON_THROW_ON_ERROR);
        $byQuery = array_column($cases, 'reply', 'query');
        $messages = [];
        Http::fake(function (Request $request) use ($byQuery, &$messages) {
            if (str_ends_with($request->url(), '/ready')) {
                return Http::response($this->ready());
            }
            $messages[] = $request['message'];
            $this->assertSame([], $request['history']);

            return Http::response($byQuery[$request['message']]);
        });
        foreach (['características', 'instalación', 'capacitación'] as $index => $objective) {
            $session = 'objetivo-'.($index + 1);
            $first = app(AsistenteVirtualService::class)->procesarMensaje('quiero cámaras', $session);
            $this->assertSame('advisor', $first['fuente']);
            $this->assertStringContainsString('capacitación publicada', $first['respuesta']);
            $next = app(AsistenteVirtualService::class)->procesarMensaje($objective, $session);
            $this->assertSame('advisor', $next['fuente']);
            $this->assertSame($cases[$index + 1]['query'], $messages[array_key_last($messages)]);
            $this->assertDatabaseMissing('chat_logs', ['mensaje_usuario' => $objective]);
        }
    }
}
