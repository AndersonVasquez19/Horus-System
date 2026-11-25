<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\ChatLog;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use Database\Seeders\ChatIntentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

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

    /** @param array<string, mixed> $datos */
    private function fila(string $sesion, array $datos = []): ChatLog
    {
        return ChatLog::create([...[
            'session_id' => $sesion, 'mensaje_usuario' => '[contenido omitido por privacidad]',
            'respuesta_bot' => 'Respuesta', 'intent_detectado' => null, 'fuente' => 'advisor', 'canal' => 'web',
        ], ...$datos]);
    }

    private function conversaciones(string $consulta = ''): TestResponse
    {
        $admin = User::query()->where('email', 'admin@horus.test')->first() ?? $this->usuarioConRol('admin', 'admin@horus.test');

        return $this->actingAs($admin)->panel('GET', 'compartido/asistente?vista=conversaciones'.$consulta)->assertOk();
    }

    public function test_agrupa_por_visitante_sin_exponer_la_sesion_ni_el_mensaje(): void
    {
        $this->fila('sesion-a', ['respuesta_bot' => 'Primera', 'intent_detectado' => 'training_info', 'voto' => 'up'])
            ->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->fila('sesion-b', ['respuesta_bot' => AsistenteVirtualService::RESPUESTA_PRIVACIDAD, 'fuente' => 'respaldo'])
            ->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $this->fila('sesion-a', ['respuesta_bot' => 'Segunda', 'intent_detectado' => 'training_info', 'voto' => 'down']);

        $r = $this->conversaciones();

        $r->assertJsonPath('meta.total', 2)->assertJsonPath('data.conversaciones.0.total', 2)
            ->assertJsonPath('data.conversaciones.0.temas', ['training_info'])
            ->assertJsonPath('data.conversaciones.0.utiles', 1)->assertJsonPath('data.conversaciones.0.no_utiles', 1)
            ->assertJsonPath('data.conversaciones.0.intercambios.0.respuesta', 'Primera')
            ->assertJsonPath('data.conversaciones.0.intercambios.1.respuesta', 'Segunda')
            ->assertJsonPath('data.conversaciones.1.intercambios.0.privacidad', true)
            ->assertJsonPath('data.conversaciones.1.intercambios.0.contingencia', true)
            ->assertJsonPath('data.conversaciones.1.intercambios.0.tema', null);
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{4}\z/', (string) $r->json('data.conversaciones.0.visitante'));
        $this->assertNotSame($r->json('data.conversaciones.0.visitante'), $r->json('data.conversaciones.1.visitante'));
        $cuerpo = (string) $r->getContent();
        $this->assertStringNotContainsString('sesion-a', $cuerpo, 'El identificador de sesión no viaja al panel.');
        $this->assertStringNotContainsString('mensaje', $cuerpo);
    }

    public function test_pagina_por_conversaciones_y_filtra_mostrando_la_conversacion_completa(): void
    {
        foreach (range(1, 12) as $i) {
            $this->fila("s-{$i}", ['respuesta_bot' => "Resp {$i}", 'intent_detectado' => 'faq']);
        }
        $this->fila('s-1', ['respuesta_bot' => 'Sin ese dato', 'intent_detectado' => 'unavailable_info', 'voto' => 'down']);
        $this->fila('s-2', ['respuesta_bot' => 'Contingencia', 'fuente' => 'respaldo']);

        $this->conversaciones()->assertJsonPath('meta.total', 12)->assertJsonPath('meta.paginas', 2)
            ->assertJsonCount(10, 'data.conversaciones');
        $this->conversaciones('&page=2')->assertJsonCount(2, 'data.conversaciones')->assertJsonPath('meta.pagina', 2);
        foreach (['&intent=sin_dato', '&intent=unavailable_info', '&voto=down', '&busqueda=ese+dato'] as $filtro) {
            $this->conversaciones($filtro)->assertJsonPath('meta.total', 1)->assertJsonPath('data.conversaciones.0.total', 2);
        }
        $this->conversaciones('&fuente=respaldo')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.conversaciones.0.intercambios.1.respuesta', 'Contingencia');
        // Valores fuera de la lista blanca se ignoran.
        $this->conversaciones('&intent=no-existe&voto=excelente&fuente=otra&dias=999')->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.filtros', []);
    }

    public function test_el_probador_se_oculta_salvo_que_se_pida_y_el_periodo_acota(): void
    {
        $this->fila('web');
        $this->fila('panel', ['canal' => 'admin']);
        $this->fila('antigua')->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->conversaciones()->assertJsonPath('meta.total', 2);
        $this->conversaciones('&canal=admin')->assertJsonPath('meta.total', 1)->assertJsonPath('data.conversaciones.0.probador', true);
        $this->conversaciones('&dias=7')->assertJsonPath('meta.total', 1);
    }

    public function test_funciona_antes_de_aplicar_la_migracion_de_metricas(): void
    {
        Schema::table('chat_logs', fn ($tabla) => $tabla->dropColumn(['canal', 'voto']));
        ChatLog::create(['session_id' => 'a', 'mensaje_usuario' => '', 'respuesta_bot' => 'Hola', 'fuente' => 'advisor']);

        $this->conversaciones('&voto=up&canal=admin')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.con_votos', false)->assertJsonPath('data.separa_canal', false)
            ->assertJsonPath('data.conversaciones.0.probador', false);
    }

    public function test_cada_vista_pide_solo_lo_suyo(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $resumen = $this->actingAs($admin)->panel('GET', 'compartido/asistente')->assertOk();
        $resumen->assertJsonPath('data.vista', 'resumen')->assertJsonPath('data.metricas.dias', 30)
            ->assertJsonMissingPath('data.conversaciones')->assertJsonMissingPath('data.intenciones');
        $this->actingAs($admin)->panel('GET', 'compartido/asistente?vista=resumen&dias=7')->assertJsonPath('data.metricas.dias', 7);
        $this->actingAs($admin)->panel('GET', 'compartido/asistente?vista=probar')->assertOk()
            ->assertJsonMissingPath('data.metricas')->assertJsonMissingPath('data.conversaciones');
    }

    public function test_permisos_y_sesion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->panel('GET', 'compartido/asistente')->assertUnauthorized();
        $this->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Hola', 'session_id' => 'sesion-0001'])->assertUnauthorized();

        $secretaria = $this->usuarioConRol('secretaria', 'sec@horus.test');
        $this->actingAs($secretaria)->panel('GET', 'compartido/asistente')->assertForbidden();
        $this->actingAs($secretaria)->panel('POST', 'compartido/asistente/mensaje', ['mensaje' => 'Hola', 'session_id' => 'sesion-0001'])->assertForbidden();

        $coordinador = $this->usuarioConRol('coordinador', 'coord@horus.test');
        $this->actingAs($coordinador)->panel('GET', 'compartido/asistente')->assertOk();
    }
}
