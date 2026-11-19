<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use Database\Seeders\ChatIntentSeeder;
use Illuminate\Support\Facades\Http;

/** Probador y registros del asistente desde el panel de apps/web. Sin llamadas de red; datos ficticios. */
final class AsistentePanelTest extends PanelApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => false]);
    }

    public function test_el_probador_responde_con_la_contingencia_y_registra_sin_texto_con_datos_personales(): void
    {
        $this->seed(ChatIntentSeeder::class);
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $normal = $this->actingAs($admin)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => '¿Qué cursos tienen?', 'session_id' => 'sesion-panel-0001'])->assertOk();
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $normal->json('respuesta'));

        $privado = $this->actingAs($admin)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Mi DNI es 12345678 y mi correo ana@example.test', 'session_id' => 'sesion-panel-0002'])->assertOk();
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD, $privado->json('respuesta'));

        $guardado = ChatLog::query()->pluck('mensaje_usuario')->implode("\n");
        $this->assertStringNotContainsString('12345678', $guardado);
        $this->assertStringNotContainsString('ana@example.test', $guardado);
        Http::assertNothingSent();
    }

    public function test_valida_la_entrada(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $this->actingAs($admin)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => '', 'session_id' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('mensaje');
        $this->actingAs($admin)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => str_repeat('a', 2001), 'session_id' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('mensaje');
        $this->actingAs($admin)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Hola', 'session_id' => str_repeat('s', 65)])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
    }

    public function test_lista_intenciones_y_ultimos_registros_con_filtro(): void
    {
        $this->seed(ChatIntentSeeder::class);
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        ChatLog::create(['session_id' => 'a', 'mensaje_usuario' => 'Hola', 'respuesta_bot' => 'Buenas', 'intent_detectado' => 'saludo', 'fuente' => 'respaldo']);
        ChatLog::create(['session_id' => 'b', 'mensaje_usuario' => 'Precio', 'respuesta_bot' => 'Consulta', 'intent_detectado' => null, 'fuente' => 'advisor']);

        $r = $this->actingAs($admin)->panel('GET', 'compartido/asistente')->assertOk();
        $this->assertNotEmpty($r->json('data.intenciones'));
        $this->assertCount(2, $r->json('data.registros'));
        $this->assertArrayNotHasKey('session_id', $r->json('data.registros.0'), 'El identificador de sesión no viaja al panel.');

        $nombre = $r->json('data.intenciones.0.nombre');
        $this->assertSame($nombre, $this->actingAs($admin)->panel('GET', 'compartido/asistente?intent='.$nombre)->json('data.filtro'));
        $this->assertNull($this->actingAs($admin)->panel('GET', 'compartido/asistente?intent=no-existe')->json('data.filtro'));
    }

    public function test_permisos_y_sesion(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->panel('GET', 'compartido/asistente')->assertUnauthorized();
        $this->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Hola', 'session_id' => 'sesion-0001'])->assertUnauthorized();

        $secretaria = $this->usuarioConRol('secretaria', 'sec@horus.test');
        $this->actingAs($secretaria)->panel('GET', 'compartido/asistente')->assertForbidden();
        $this->actingAs($secretaria)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Hola', 'session_id' => 'sesion-0001'])->assertForbidden();

        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');
        $this->actingAs($coordinador)->panel('GET', 'compartido/asistente')->assertOk();
    }
}
