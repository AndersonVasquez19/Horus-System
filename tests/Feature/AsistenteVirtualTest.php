<?php

namespace Tests\Feature;

use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use App\Support\ChatKnowledge;
use Database\Seeders\ChatIntentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsistenteVirtualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'test-openai-key', 'services.openai.model' => 'gpt-test']);
        Http::preventStrayRequests();
    }

    public function test_known_questions_use_contingency_when_python_is_disabled(): void
    {
        $this->seed(ChatIntentSeeder::class);
        $asistente = app(AsistenteVirtualService::class);

        $precio = $asistente->procesarMensaje('Hola, ¿cuánto cuesta el curso?', 'session-precios');
        $evaluacion = $asistente->procesarMensaje('¿Cuándo es el examen del curso grabado?', 'session-evaluacion');
        $cableado = $asistente->procesarMensaje('Necesito cableado UTP', 'session-cableado');

        $this->assertNull($precio['intent']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $precio['respuesta']);
        $this->assertNull($evaluacion['intent']);
        $this->assertNull($cableado['intent']);
        $this->assertDatabaseHas('chat_logs', ['intent_detectado' => null, 'mensaje_usuario' => '[contenido omitido por privacidad]']);
        Http::assertNothingSent();
    }

    public function test_rule_answers_are_available_before_seeding_and_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);

        $answer = app(AsistenteVirtualService::class)->procesarMensaje('¿Dónde están ubicados?', 'session-unseeded');

        $this->assertNull($answer['intent']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertSame(0, ChatIntent::count());
        Http::assertNothingSent();
    }

    public function test_disabled_intent_does_not_get_reactivated_by_builtin_knowledge(): void
    {
        $this->seed(ChatIntentSeeder::class);
        ChatIntent::where('nombre', 'consulta_camaras')->update(['activo' => false]);
        config(['services.openai.api_key' => null]);

        $answer = app(AsistenteVirtualService::class)->procesarMensaje('cámaras', 'session-disabled');

        $this->assertNull($answer['intent']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
    }

    public function test_legacy_and_custom_intents_do_not_supply_public_replies(): void
    {
        ChatIntent::create([
            'nombre' => 'consulta_precios', 'prioridad' => 1, 'keywords' => ['precio'],
            'respuesta' => 'Respuesta antigua', 'activo' => true,
        ]);
        ChatIntent::create([
            'nombre' => 'consulta_personalizada', 'prioridad' => 20, 'keywords' => ['convenio especial'],
            'respuesta' => 'Consulta personalizada.', 'activo' => true,
        ]);

        $asistente = app(AsistenteVirtualService::class);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $asistente->procesarMensaje('precio', 'session-updated')['respuesta']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $asistente->procesarMensaje('convenio especial', 'session-custom')['respuesta']);
        Http::assertNothingSent();
    }

    public function test_complex_orientation_never_calls_openai_and_uses_safe_fallback(): void
    {
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Compara las modalidades de cursos para orientarme', 'session-openai');

        $this->assertNull($answer['intent']);
        $this->assertSame('respaldo', $answer['fuente']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        Http::assertNothingSent();
    }

    public function test_openai_failure_returns_a_useful_fallback_instead_of_503(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 429)]);

        $this->postJson(route('chat.mensaje'), [
            'mensaje' => 'Compara las modalidades de cursos para orientarme',
            'session_id' => 'session-error',
        ])->assertOk()->assertJsonPath('respuesta', AsistenteVirtualService::RESPUESTA_FALLBACK);

        $this->assertDatabaseHas('chat_logs', [
            'mensaje_usuario' => '[contenido omitido por privacidad]',
            'respuesta_bot' => AsistenteVirtualService::RESPUESTA_FALLBACK,
        ]);
    }

    public function test_public_and_admin_chat_endpoints_work(): void
    {
        $this->postJson(route('chat.mensaje'), ['mensaje' => 'humano', 'session_id' => 'public-session'])
            ->assertOk()->assertJsonPath('intent', null);

        $this->seed(ChatIntentSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)
            ->postJson(route('admin.asistente.mensaje'), ['mensaje' => 'cursos disponibles', 'session_id' => 'admin-session'])
            ->assertOk()->assertJsonPath('intent', null);
        $this->get(route('admin.asistente.index'))
            ->assertOk()->assertSee('[contenido omitido por privacidad]')
            ->assertSee('Horus AI Python')->assertSee('Registro histórico')
            ->assertDontSee('Prueba las respuestas por reglas');
        $this->assertSame(2, ChatLog::count());
    }

    public function test_public_pages_render_the_chat_widget(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee('aria-label="Abrir chat con el asistente Horus"', false)
            ->assertSee('aria-controls="horus-chat-panel"', false)
            ->assertSee('id="horus-chat-panel"', false)
            ->assertSee(':aria-expanded="abierto.toString()"', false)
            ->assertSee('chat/mensaje', false);
    }

    public function test_original_attention_hours_are_preserved_and_chat_explains_the_discrepancy(): void
    {
        $this->get('/contactos')->assertOk()->assertSee('Lunes - Viernes: 9:00 AM - 6:00 PM')->assertSee('Sábados: 9:00 AM - 1:00 PM');
        $this->get('/preguntas-frecuentes')->assertOk()->assertSee('lunes a viernes de 8am a 6pm');
        $this->get('/politicas-devolucion')->assertOk()->assertSee('sábados de 9:00 am – 1:00 pm');
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Horario de atención', 'hours');
        $original = collect(ChatKnowledge::intents())->firstWhere('nombre', 'consulta_horarios');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertStringContainsString('/contactos', $original['respuesta']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        Http::assertNothingSent();
    }
}
