<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\ContenidoPublicoServicios;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use App\Services\PresentacionRecomendacionesHorus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Datos públicos ficticios; FastAPI simulado, SQLite memoria. No reservas restringidas. */
class AdvisorTarjetasPresentacionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalogo;

    /** @var array<string, mixed> */
    private array $respuesta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/phase-c-public-replies.json')), true, 512, JSON_THROW_ON_ERROR);
        $firma = app(FirmaCatalogoAdvisor::class);
        $contenido = ['managed_types' => ['curso', 'servicio'], 'items' => $fixture['items']];
        $this->catalogo = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido($contenido),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(), ...$contenido];
        $this->catalogo['signature'] = $firma->firmar($this->catalogo);
        $this->respuesta = array_values(array_filter($fixture['cases'], static fn (array $c): bool => $c['reply']['intent'] === 'course_recommendation' && $c['reply']['recommendations'] !== []))[0]['reply'];
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturnUsing(fn (): array => $this->catalogo);
        Cache::forever('horus-ai.catalog.expected-version', $this->catalogo['catalog_version']);
        Http::preventStrayRequests();
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $this->catalogo['catalog_version']]),
            '*/api/v1/chat' => Http::response($this->respuesta)]);
    }

    public function test_valid_recommendations_reach_the_public_controller_as_current_cards(): void
    {
        $response = $this->postJson('/chat/mensaje', ['mensaje' => 'Me interesa estudiar Python', 'session_id' => 'cards']);
        $response->assertOk()->assertJsonPath('fuente', 'advisor')->assertJsonPath('tarjetas.0.id', 'cat_curso_datos')
            ->assertJsonPath('tarjetas.0.titulo', 'Analisis de datos con Python')
            ->assertJsonPath('tarjetas.0.descripcion', 'Aprende analisis de datos con Python.')
            ->assertJsonPath('tarjetas.0.imagen', null)->assertJsonPath('tarjetas.0.url', '/educacion/cursos')
            ->assertJsonPath('tarjetas.0.accion', 'Ver cursos')
            ->assertJsonPath('tarjetas.0.procedencia.version', $this->catalogo['catalog_version']);
        $this->assertArrayNotHasKey('recomendaciones', $response->json());
        $this->assertSame($this->respuesta['answer'], $response->json('respuesta'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/v1/chat') && $r->data() === ['message' => 'Me interesa estudiar Python', 'history' => []]);
    }

    public function test_internal_references_survive_cache_and_are_revalidated(): void
    {
        $service = app(AsistenteVirtualService::class);
        $first = $service->procesarMensaje('Me interesa estudiar Python', 'cached');
        $this->assertCount(1, $first['recomendaciones']);
        $this->assertSame($first, $service->procesarMensaje('Me interesa estudiar Python', 'cached'));
        Http::assertSentCount(2);
        $this->assertTrue(app(HorusAdvisorClient::class)->respuestaCacheValida($first));
        $this->assertFalse(app(HorusAdvisorClient::class)->respuestaCacheValida([...$first, 'respuesta' => 'Mi correo es prueba@example.invalid']));
        $this->catalogo['items'] = [];
        $this->catalogo['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($this->catalogo);
        $this->assertFalse(app(HorusAdvisorClient::class)->respuestaCacheValida($first));
        $next = $service->procesarMensaje('Me interesa estudiar Python', 'cached');
        $this->assertSame('respaldo', $next['fuente']);
        $this->assertArrayNotHasKey('recomendaciones', $next);
    }

    public function test_legacy_cache_and_plain_text_replies_keep_their_three_fields(): void
    {
        $legacy = ['respuesta' => 'Hola', 'intent' => null, 'fuente' => 'advisor'];
        $this->assertTrue(app(HorusAdvisorClient::class)->respuestaCacheValida($legacy));
        $this->assertSame($legacy, app(PresentacionRecomendacionesHorus::class)->paraFrontend($legacy));
        $fallback = ['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'intent' => null, 'fuente' => 'respaldo'];
        $this->assertTrue(app(HorusAdvisorClient::class)->respuestaCacheValida($fallback));
        $this->assertSame($fallback, app(PresentacionRecomendacionesHorus::class)->paraFrontend($fallback));
    }

    public function test_invalid_and_withdrawn_references_cannot_reach_the_frontend(): void
    {
        $reply = app(HorusAdvisorClient::class)->orientar('Me interesa estudiar Python');
        $this->assertNotNull($reply);
        $bad = $reply;
        $bad['recomendaciones'][0]['url_path'] = 'https://evil.example';
        $this->assertFalse(app(HorusAdvisorClient::class)->respuestaCacheValida($bad));
        $this->assertArrayNotHasKey('tarjetas', app(PresentacionRecomendacionesHorus::class)->paraFrontend($bad));
        $this->catalogo['items'] = [];
        $this->catalogo['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($this->catalogo);
        $this->assertArrayNotHasKey('tarjetas', app(PresentacionRecomendacionesHorus::class)->paraFrontend($reply));
    }

    public function test_cache_rejects_unknown_duplicate_or_non_list_references(): void
    {
        $client = app(HorusAdvisorClient::class);
        $reply = $client->orientar('Me interesa estudiar Python');
        $this->assertNotNull($reply);
        foreach ([[['id' => 'cat_missing', 'title' => 'Curso', 'url_path' => null]], ['id' => 'x'], array_fill(0, 6, $reply['recomendaciones'][0])] as $refs) {
            $this->assertFalse($client->respuestaCacheValida([...$reply, 'recomendaciones' => $refs]));
        }
    }

    public function test_privacy_blocked_input_still_has_no_http_or_cards(): void
    {
        $response = $this->postJson('/chat/mensaje', ['mensaje' => 'Mi correo es prueba@example.invalid', 'session_id' => 'private']);
        $response->assertOk()->assertJsonPath('fuente', 'respaldo');
        $this->assertArrayNotHasKey('tarjetas', $response->json());
        Http::assertNothingSent();
        $this->assertDatabaseHas('chat_logs', ['mensaje_usuario' => '[contenido omitido por privacidad]']);
    }

    public function test_invalid_recommendations_from_fastapi_are_rejected_before_presentation(): void
    {
        $this->respuesta['recommendations'][0]['url_path'] = 'https://evil.example';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'loaded', 'catalog' => 'loaded', 'catalog_version' => $this->catalogo['catalog_version']]),
            '*/api/v1/chat' => Http::response($this->respuesta)]);
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar('Me interesa estudiar Python'));
        $this->assertSame('respuesta_rechazada', $client->motivoContingencia());
    }

    public function test_a_legacy_cache_entry_is_reused_without_new_http(): void
    {
        $service = app(AsistenteVirtualService::class);
        $service->procesarMensaje('Me interesa estudiar Python', 'legacy');
        $legacy = ['respuesta' => 'Hola', 'intent' => null, 'fuente' => 'advisor'];
        $keys = array_filter(array_keys(Cache::getStore()->all()), static fn (string $key): bool => str_starts_with($key, 'chat:reply:'));
        $this->assertCount(1, $keys);
        $key = array_values($keys)[0];
        Cache::put($key, ['reply' => $legacy, 'mac' => hash_hmac('sha256', $key.serialize($legacy), (string) config('app.key'))], 60);
        $this->assertSame($legacy, $service->procesarMensaje('Me interesa estudiar Python', 'legacy'));
        Http::assertSentCount(2);
    }

    public function test_only_the_current_published_service_cover_is_used(): void
    {
        $item = ['stable_id' => 'servicio:pagina-cableado', 'type' => 'servicio', 'publication_status' => 'vigente',
            'title' => 'Cableado estructurado', 'summary' => 'Instalacion y mantenimiento de redes empresariales.',
            'url_path' => '/tecnologias/cableado-estructurado', 'updated_at' => now()->toIso8601String()];
        $this->catalogo['items'] = [$item];
        $this->catalogo['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($this->catalogo);
        $pagina = ['titulo' => $item['title'], 'parrafos' => [$item['summary']], 'imagen_url' => asset('assets/images/tecnologias/cableado.jpg')];
        $this->mock(ContenidoPublicoServicios::class)->shouldReceive('pagina')->with('CABLEADO')->andReturnUsing(function () use (&$pagina): array {
            return $pagina;
        });
        $reply = ['respuesta' => 'Informacion publicada.', 'intent' => null, 'fuente' => 'advisor',
            'recomendaciones' => [['id' => 'cat_servicio_pagina_cableado', 'title' => $item['title'], 'url_path' => $item['url_path']]]];
        $presentacion = app(PresentacionRecomendacionesHorus::class);
        $card = $presentacion->paraFrontend($reply)['tarjetas'][0];
        $this->assertSame('/assets/images/tecnologias/cableado.jpg', $card['imagen']);
        $this->assertSame('servicio', $card['tipo']);
        foreach (['https://evil.example/assets/images/tecnologias/cableado.jpg', '/storage/private.png',
            '/assets/images/tecnologias/cableado.jpg?x=1', '/assets/images/tecnologias/cableado.jpg#fragment'] as $url) {
            $pagina['imagen_url'] = $url;
            $this->assertNull($presentacion->paraFrontend($reply)['tarjetas'][0]['imagen']);
        }
        $pagina['titulo'] = 'Otra publicacion';
        $this->assertNull($presentacion->paraFrontend($reply)['tarjetas'][0]['imagen']);
    }

    public function test_unavailable_expired_or_manipulated_catalogs_never_produce_cards(): void
    {
        $reply = app(HorusAdvisorClient::class)->orientar('Me interesa estudiar Python');
        $this->assertNotNull($reply);
        $this->catalogo['signature'] = str_repeat('0', 64);
        $this->assertArrayNotHasKey('tarjetas', app(PresentacionRecomendacionesHorus::class)->paraFrontend($reply));
        $this->catalogo['expires_at'] = now()->subMinute()->toIso8601String();
        $this->catalogo['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($this->catalogo);
        $this->assertArrayNotHasKey('tarjetas', app(PresentacionRecomendacionesHorus::class)->paraFrontend($reply));
    }
}
