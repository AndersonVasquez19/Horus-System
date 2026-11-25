<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\PresentacionRecomendacionesHorus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Fase 2b: `POST /api/public/v1/chat`, el motor existente del chat expuesto solo al BFF (transporte simulado). */
class ApiPublicaChatTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['horus-web.service_token' => self::TOKEN, 'horus-advisor.enabled' => false]);
        Http::preventStrayRequests();
    }

    /** @return array<string, string> */
    private function bff(?string $visitante = null): array
    {
        return ['X-Horus-Web-Token' => self::TOKEN] + ($visitante === null ? [] : ['X-Horus-Visitante' => $visitante]);
    }

    /** @return array<string, string> */
    private function cuerpo(string $mensaje = '¿Qué cursos hay?'): array
    {
        return ['mensaje' => $mensaje, 'session_id' => 'sesion-de-prueba-0123'];
    }

    public function test_exige_el_token_de_servicio_y_no_acepta_get(): void
    {
        $this->postJson('/api/public/v1/chat', $this->cuerpo())->assertUnauthorized();
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), ['X-Horus-Web-Token' => 'otro-token-otro-token-otro-token-otro!'])->assertUnauthorized();
        $this->getJson('/api/public/v1/chat', $this->bff())->assertStatus(405);
        config(['horus-web.service_token' => '']);
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertNotFound();
    }

    public function test_sin_horus_ai_responde_la_contingencia_institucional_sin_campos_internos(): void
    {
        $r = $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertOk();
        // `respuesta_id` es la referencia firmada para votar; nada interno viaja al navegador.
        $referencia = (string) $r->json('respuesta_id');
        $this->assertMatchesRegularExpression('/\A[1-9]\d*\.[a-f0-9]{64}\z/', $referencia);
        $r->assertExactJson(['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'fuente' => 'respaldo', 'respuesta_id' => $referencia]);
        $r->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('intent');
        // Solo metadatos: nunca el texto libre del visitante.
        $this->assertSame('[contenido omitido por privacidad]', ChatLog::query()->value('mensaje_usuario'));
    }

    public function test_un_dato_personal_recibe_la_aclaracion_de_privacidad_y_no_llega_a_la_ia(): void
    {
        $this->postJson('/api/public/v1/chat', $this->cuerpo('Mi correo es persona@ejemplo.com, ¿me ayudan?'), $this->bff())->assertOk()
            ->assertJsonPath('respuesta', AsistenteVirtualService::RESPUESTA_PRIVACIDAD)->assertJsonPath('fuente', 'respaldo');
        Http::assertNothingSent();
        $this->assertStringNotContainsString('persona@ejemplo.com', (string) json_encode(ChatLog::query()->get()->toArray()));
    }

    public function test_valida_mensaje_y_sesion(): void
    {
        $this->postJson('/api/public/v1/chat', ['session_id' => 'sesion-de-prueba-0123'], $this->bff())->assertStatus(422)->assertJsonValidationErrors('mensaje');
        $this->postJson('/api/public/v1/chat', $this->cuerpo(''), $this->bff())->assertStatus(422)->assertJsonValidationErrors('mensaje');
        $this->postJson('/api/public/v1/chat', $this->cuerpo(str_repeat('a', 2001)), $this->bff())->assertStatus(422)->assertJsonValidationErrors('mensaje');
        $this->postJson('/api/public/v1/chat', ['mensaje' => ['x']] + $this->cuerpo(), $this->bff())->assertStatus(422);
        foreach (['', 'corta', 'con espacios y simbolos <>', str_repeat('a', 65)] as $sesion) {
            $this->postJson('/api/public/v1/chat', ['session_id' => $sesion] + $this->cuerpo(), $this->bff())->assertStatus(422)->assertJsonValidationErrors('session_id');
        }
        $this->postJson('/api/public/v1/chat', $this->cuerpo(str_repeat('a', 2000)), $this->bff())->assertOk();
    }

    public function test_solo_se_devuelven_campos_de_presentacion_y_las_tarjetas_si_existen(): void
    {
        $tarjeta = ['id' => 'cat_x', 'tipo' => 'curso', 'titulo' => 'Curso', 'descripcion' => 'Resumen', 'imagen' => null, 'url' => '/educacion/cursos', 'accion' => 'Ver cursos'];
        $asistente = $this->mock(AsistenteVirtualService::class);
        $asistente->shouldReceive('referenciaRespuesta')->andReturnNull();
        $asistente->shouldReceive('procesarMensaje')->once()
            ->andReturn(['respuesta' => 'Hola', 'intent' => 'interno', 'fuente' => 'advisor', 'recomendaciones' => []]);
        $this->mock(PresentacionRecomendacionesHorus::class)->shouldReceive('paraFrontend')->once()
            ->andReturn(['respuesta' => 'Hola', 'intent' => 'interno', 'fuente' => 'advisor', 'tarjetas' => [$tarjeta]]);

        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertOk()
            ->assertExactJson(['respuesta' => 'Hola', 'fuente' => 'advisor', 'tarjetas' => [$tarjeta]]);
    }

    public function test_un_fallo_tecnico_responde_503_generico_sin_registrar_el_mensaje(): void
    {
        $this->mock(AsistenteVirtualService::class)->shouldReceive('procesarMensaje')->andThrow(new RuntimeException('SECRETO-interno mensaje-del-visitante'));
        Log::spy();

        $this->postJson('/api/public/v1/chat', $this->cuerpo('mensaje-del-visitante'), $this->bff())->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'chat_no_disponible', 'message' => 'El asistente no está disponible.']]);
        Log::shouldHaveReceived('warning')->with('chat_bff_fallo_tecnico')->once();
    }

    public function test_el_limite_es_por_visitante_y_un_identificador_invalido_se_agrupa_como_anonimo(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        foreach (range(1, 20) as $_) {
            $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff($a))->assertOk();
        }
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff($a))->assertStatus(429);
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff($b))->assertOk();
        // Un valor mal formado no abre un cubo propio: comparte el de anonimo.
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff('no-es-hmac'))->assertOk();
    }

    public function test_una_sesion_ocupada_responde_429_con_retry_after_y_no_un_texto_como_respuesta(): void
    {
        // Auditoría del 25/11/2026: «Tu consulta anterior sigue en proceso» llegaba como 200 y el widget lo mostraba
        // como si fuera la contestación del asistente.
        $sesion = hash_hmac('sha256', 'web:anonimo|sesion-de-prueba-0123', (string) config('app.key'));
        $lock = Cache::lock('chat:lock:'.$sesion, 30);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertStatus(429)
                ->assertHeader('Retry-After', '3')
                ->assertExactJson(['error' => ['code' => 'consulta_en_proceso', 'message' => 'Tu consulta anterior sigue en proceso.']]);
        } finally {
            $lock->release();
        }
        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertOk();
    }

    public function test_un_fallo_al_guardar_el_registro_no_anula_la_respuesta(): void
    {
        // El registro es auditoría: sin la tabla, la persona igual recibe la respuesta (antes era un 503).
        Schema::drop('chat_logs');
        Log::spy();

        $this->postJson('/api/public/v1/chat', $this->cuerpo(), $this->bff())->assertOk()
            ->assertExactJson(['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'fuente' => 'respaldo']);
        Log::shouldHaveReceived('warning')->with('chatbot.registro_fallo')->once();
    }

    public function test_la_ruta_de_blade_del_chat_ya_no_existe(): void
    {
        // Retirada en la Fase 1 del retiro de Blade: el chat público solo se atiende por la ruta del BFF.
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('chat.mensaje'));
        $this->postJson('/chat/mensaje', ['mensaje' => 'hola', 'session_id' => 'sesion-de-prueba-0123'])->assertNotFound();
    }
}
