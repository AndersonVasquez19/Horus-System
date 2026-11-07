<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatComprensionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{query: string, reply: array<string, mixed>}> */
    private array $cases;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        Http::preventStrayRequests();
        $this->cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-comprension-replies.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_las_consultas_de_las_capturas_se_responden_directamente(): void
    {
        foreach (array_slice($this->cases, 0, 2) as $case) {
            Http::fake(['*/ready' => $this->ready(), '*/api/v1/chat' => Http::response($case['reply'])]);
            $this->postJson(route('chat.mensaje'), ['mensaje' => $case['query'], 'session_id' => 'camara-prueba'])
                ->assertOk()->assertJsonPath('respuesta', $case['reply']['answer'])->assertJsonPath('fuente', 'advisor');
        }
    }

    public function test_si_resuelve_una_aclaracion_sin_enviar_historial(): void
    {
        $messages = [];
        $reply = $this->cases[0]['reply'];
        Http::fake(function (Request $request) use (&$messages, $reply) {
            if (str_ends_with($request->url(), '/ready')) {
                return $this->ready();
            }
            $messages[] = $request['message'];
            $this->assertSame([], $request['history']);
            $this->assertArrayNotHasKey('session_id', $request->data());
            if (count($messages) === 1) {
                return Http::response([...$reply, 'answer' => 'Puedo orientarte sobre información pública.',
                    'intent' => 'clarification', 'match_level' => 'baja', 'needs_clarification' => true,
                    'clarification_question' => '¿Te refieres a cámaras de seguridad?', 'sources' => [],
                    'context' => ['focus_ids' => ['servicio_camaras']]]);
            }

            return Http::response($reply);
        });
        $service = app(AsistenteVirtualService::class);
        $first = $service->procesarMensaje('Quiero información', 'same-session', 'public:uno');
        $this->assertStringContainsString('¿Te refieres', $first['respuesta']);
        $next = $service->procesarMensaje('sí', 'same-session', 'public:uno');
        $this->assertSame($reply['answer'], $next['respuesta']);
        $this->assertSame('Información sobre Cámaras de seguridad y videovigilancia', $messages[1]);
        $this->assertDatabaseMissing('chat_logs', ['mensaje_usuario' => 'Quiero información']);
        $this->assertDatabaseMissing('chat_logs', ['mensaje_usuario' => 'sí']);
        $this->assertStringNotContainsString('Quiero información', serialize(Cache::getStore()->all()));
    }

    public function test_el_tema_no_se_comparte_entre_sesiones_y_vence(): void
    {
        $messages = [];
        Http::fake(function (Request $request) use (&$messages) {
            if (str_ends_with($request->url(), '/ready')) {
                return $this->ready();
            }
            $messages[] = $request['message'];

            return Http::response($this->cases[0]['reply']);
        });
        $service = app(AsistenteVirtualService::class);
        $service->procesarMensaje('que cámaras tienen', 'same-session', 'public:uno');
        $service->procesarMensaje('sí', 'same-session', 'public:dos');
        $this->assertSame('sí', $messages[1]);
        $this->travel(6)->minutes();
        $service->procesarMensaje('sí', 'same-session', 'public:uno');
        $this->assertSame('sí', $messages[2]);
    }

    public function test_seguimiento_revalida_fuentes_y_no_adivina_entre_dos_opciones(): void
    {
        $client = app(HorusAdvisorClient::class);
        $this->assertSame('sí', $client->resolverSeguimiento('sí', ['fuente_inexistente']));
        $this->assertSame('sí', $client->resolverSeguimiento('sí', ['cat_servicio_retirado']));
        $this->assertSame('sí', $client->resolverSeguimiento('sí', ['servicio_camaras', 'servicio_soporte']));
        $this->assertSame('Información sobre Soporte y mantenimiento de equipos',
            $client->resolverSeguimiento('la segunda', ['servicio_camaras', 'servicio_soporte']));
        $this->assertSame('y qué incluye de Cámaras de seguridad y videovigilancia',
            $client->resolverSeguimiento('y qué incluye', ['servicio_camaras']));
        $this->assertSame('ahora quiero cableado', $client->resolverSeguimiento('ahora quiero cableado', ['servicio_camaras']));
    }

    public function test_una_respuesta_duplicada_restaura_su_tema_y_la_privacidad_prevalece(): void
    {
        $messages = [];
        $raw = json_decode((string) file_get_contents(base_path('chatbot-python/data/knowledge.json')), true, 512, JSON_THROW_ON_ERROR);
        $support = collect($raw['records'])->firstWhere('id', 'servicio_soporte');
        Http::fake(function (Request $request) use (&$messages, $support) {
            if (str_ends_with($request->url(), '/ready')) {
                return $this->ready();
            }
            $messages[] = $request['message'];
            $reply = $this->cases[0]['reply'];
            if (str_contains($request['message'], 'soporte')) {
                $reply['answer'] = $support['answer'];
                $reply['sources'] = [['id' => $support['id'], 'title' => $support['title'], 'url_path' => $support['source']['url_path']]];
            }

            return Http::response($reply);
        });
        $service = app(AsistenteVirtualService::class);
        $camera = $service->procesarMensaje('que cámaras tienen', 'topic-cache');
        $service->procesarMensaje('soporte y mantenimiento', 'topic-cache');
        $this->assertSame($camera, $service->procesarMensaje('que cámaras tienen', 'topic-cache'));
        $this->assertCount(2, $messages);
        $service->procesarMensaje('sí', 'topic-cache');
        $this->assertSame('Información sobre Cámaras de seguridad y videovigilancia', $messages[2]);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD,
            $service->procesarMensaje('me llamo Zelvani Quenzuli', 'topic-cache')['respuesta']);
        $this->assertCount(3, $messages);
    }

    private function ready(): PromiseInterface
    {
        return Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => 'loaded', 'catalog_version' => str_repeat('a', 40)]);
    }
}
