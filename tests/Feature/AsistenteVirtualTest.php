<?php

namespace Tests\Feature;

use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use Database\Seeders\ChatIntentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_known_questions_use_verified_local_answers_and_log_the_intent(): void
    {
        $this->seed(ChatIntentSeeder::class);
        $asistente = app(AsistenteVirtualService::class);

        $precio = $asistente->procesarMensaje('Hola, ¿cuánto cuesta el curso?', 'session-precios');
        $evaluacion = $asistente->procesarMensaje('¿Cuándo es el examen del curso grabado?', 'session-evaluacion');
        $cableado = $asistente->procesarMensaje('Necesito cableado UTP', 'session-cableado');

        $this->assertSame('consulta_precios', $precio['intent']);
        $this->assertStringContainsString('cotización', $precio['respuesta']);
        $this->assertSame('consulta_evaluaciones', $evaluacion['intent']);
        $this->assertSame('consulta_cableado', $cableado['intent']);
        $this->assertDatabaseHas('chat_logs', ['intent_detectado' => 'consulta_precios', 'mensaje_usuario' => '[contenido omitido por privacidad]']);
        Http::assertNothingSent();
    }

    public function test_rule_answers_are_available_before_seeding_and_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);

        $answer = app(AsistenteVirtualService::class)->procesarMensaje('¿Dónde están ubicados?', 'session-unseeded');

        $this->assertSame('consulta_ubicacion', $answer['intent']);
        $this->assertStringContainsString('José Gálvez', $answer['respuesta']);
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

    public function test_updated_knowledge_applies_to_existing_rows_and_custom_intents_still_work(): void
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
        $this->assertStringContainsString('cotización', $asistente->procesarMensaje('precio', 'session-updated')['respuesta']);
        $this->assertSame('Consulta personalizada.', $asistente->procesarMensaje('convenio especial', 'session-custom')['respuesta']);
        Http::assertNothingSent();
    }

    public function test_complex_orientation_uses_openai_to_select_only_verified_facts(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"intents":["consulta_modalidades"]}']]]])]);

        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Compara las modalidades de cursos para orientarme', 'session-openai');

        $this->assertNull($answer['intent']);
        $this->assertSame('openai', $answer['fuente']);
        $this->assertStringContainsString('semipresencial', $answer['respuesta']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request['model'] === 'gpt-test'
            && str_contains($request['messages'][0]['content'], 'consulta_cursos'));
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
            ->assertOk()->assertJsonPath('intent', 'derivar_humano');

        $this->seed(ChatIntentSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)
            ->postJson(route('admin.asistente.mensaje'), ['mensaje' => 'cursos disponibles', 'session_id' => 'admin-session'])
            ->assertOk()->assertJsonPath('intent', 'consulta_cursos');
        $this->get(route('admin.asistente.index', ['intent' => 'consulta_cursos']))
            ->assertOk()->assertSee('[contenido omitido por privacidad]');
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
}
