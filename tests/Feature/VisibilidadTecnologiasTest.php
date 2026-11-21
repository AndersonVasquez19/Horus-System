<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use App\Support\VisibilidadTecnologias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class VisibilidadTecnologiasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    public function test_la_seccion_esta_oculta_por_defecto_en_la_web_y_el_panel(): void
    {
        $this->assertFalse(config('tecnologias.visible'));
        $this->get('/')->assertOk()->assertDontSee('/tecnologias/', false)
            ->assertDontSee('Soluciones tecnológicas')->assertDontSee('Servicios tecnológicos')
            ->assertDontSee('Solicitar una cotización')->assertSee('carousel(3)', false)
            ->assertSee('Cursos, capacitaciones y orientación')->assertSee('Ver cursos');
        $this->get('/contactos')->assertOk()->assertDontSee('value="Tecnologías"', false)->assertSee('value="Educación"', false);
        $this->actingAs($this->adminUser())->get('/admin')->assertOk()
            ->assertDontSee('/admin/servicios', false)->assertDontSee('/admin/cotizaciones', false)
            ->assertSee('/admin/cursos', false)->assertSee('/admin/matriculas', false);
    }

    public function test_la_visibilidad_se_puede_reponer_sin_cambiar_datos_ni_permisos(): void
    {
        config(['tecnologias.visible' => true]);
        $this->get('/')->assertOk()->assertSee('/tecnologias/camaras-seguridad', false)
            ->assertSee('Servicios tecnológicos')->assertSee('carousel(4)', false);
        $this->get('/contactos')->assertSee('value="Tecnologías"', false);
        $this->actingAs($this->adminUser())->get('/admin')->assertOk()
            ->assertSee('/admin/servicios', false)->assertSee('/admin/cotizaciones', false);
    }

    public function test_ambos_chats_ocultan_respuestas_y_tarjetas_tecnologicas(): void
    {
        $this->simularAdvisor(['respuesta' => 'Consulta nuestras cámaras de seguridad.', 'intent' => null,
            'fuente' => 'advisor', 'recomendaciones' => [['id' => 'servicio_camaras', 'title' => 'Cámaras',
                'url_path' => '/tecnologias/camaras-seguridad']]]);
        $datos = ['mensaje' => 'Qué cámaras tienen', 'session_id' => 'visibilidad'];
        $this->postJson(route('chat.mensaje'), $datos)->assertOk()
            ->assertJsonPath('respuesta', VisibilidadTecnologias::ORIENTACION)
            ->assertJsonMissingPath('recomendaciones')->assertJsonMissingPath('tarjetas');
        $this->actingAs($this->adminUser())->postJson(route('admin.asistente.mensaje'), $datos)->assertOk()
            ->assertJsonPath('respuesta', VisibilidadTecnologias::ORIENTACION);
        $this->assertDatabaseMissing('chat_logs', ['respuesta_bot' => 'Consulta nuestras cámaras de seguridad.']);
        Http::assertNothingSent();
    }

    public function test_cambiar_visibilidad_invalida_la_respuesta_y_el_foco_previos(): void
    {
        $original = ['respuesta' => 'Información de cableado estructurado.', 'intent' => null, 'fuente' => 'advisor'];
        $this->simularAdvisor($original, ['servicio_cableado']);
        config(['tecnologias.visible' => true]);
        $asistente = app(AsistenteVirtualService::class);
        $this->assertSame($original, $asistente->procesarMensaje('Cableado', 'misma-sesion'));
        config(['tecnologias.visible' => false]);
        $this->assertSame(VisibilidadTecnologias::ORIENTACION,
            $asistente->procesarMensaje('Cableado', 'misma-sesion')['respuesta']);
        $this->assertSame(VisibilidadTecnologias::ORIENTACION,
            $asistente->procesarMensaje('sí', 'misma-sesion')['respuesta']);
    }

    public function test_conserva_respuestas_educativas_y_la_privacidad(): void
    {
        $original = ['respuesta' => 'Consulta los cursos en /educacion/cursos.', 'intent' => null, 'fuente' => 'advisor'];
        $this->simularAdvisor($original);
        $asistente = app(AsistenteVirtualService::class);
        $this->assertSame($original, $asistente->procesarMensaje('Cursos', 'educacion'));
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD,
            $asistente->procesarMensaje('me llamo Zelvani Quenzuli', 'privacidad')['respuesta']);
    }

    public function test_una_consulta_oculta_repetida_no_vuelve_a_consultar_python(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        $casos = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-comprension-replies.json'), true, 512, JSON_THROW_ON_ERROR);
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded',
            'auth' => 'configured', 'semantic' => 'disabled', 'catalog' => 'none']),
            '*/api/v1/chat' => Http::response($casos[0]['reply'])]);
        $asistente = app(AsistenteVirtualService::class);
        $primera = $asistente->procesarMensaje('que cámaras tienen', 'cache-real');
        $this->assertSame(VisibilidadTecnologias::ORIENTACION, $primera['respuesta']);
        $this->assertSame($primera, $asistente->procesarMensaje('que cámaras tienen', 'cache-real'));
        Http::assertSentCount(2);
        config(['tecnologias.visible' => true]);
        $this->assertSame($casos[0]['reply']['answer'], $asistente->procesarMensaje('que cámaras tienen', 'cache-real')['respuesta']);
        Http::assertSentCount(4);
    }

    public function test_no_filtra_solo_por_el_texto_si_hay_una_tarjeta_o_un_foco_tecnologico(): void
    {
        $this->assertTrue(VisibilidadTecnologias::respuestaOculta(['respuesta' => 'Más información',
            'tarjetas' => [['url' => '/tecnologias/soporte-mantenimiento']]]));
        $this->assertTrue(VisibilidadTecnologias::respuestaOculta(['respuesta' => 'Más información',
            'tarjetas' => [['tipo' => 'servicio', 'titulo' => 'Instalación a domicilio']]]));
        $this->simularAdvisor(['respuesta' => '¿Qué información necesitas?', 'intent' => null, 'fuente' => 'advisor'], ['servicio_soporte']);
        $this->assertSame(VisibilidadTecnologias::ORIENTACION,
            app(AsistenteVirtualService::class)->procesarMensaje('Información', 'foco')['respuesta']);
    }

    /** @param array<string, mixed> $respuesta
     * @param  list<string>  $foco
     */
    private function simularAdvisor(array $respuesta, array $foco = []): void
    {
        $this->mock(HorusAdvisorClient::class, function (MockInterface $mock) use ($respuesta, $foco): void {
            $mock->shouldReceive('orientar')->andReturn($respuesta);
            $mock->shouldReceive('focoConversacional')->andReturn($foco);
            $mock->shouldReceive('motivoContingencia')->andReturn(null);
            $mock->shouldReceive('respuestaCacheValida')->andReturn(true);
            $mock->shouldReceive('resolverSeguimiento')->andReturnUsing(fn (string $mensaje) => $mensaje);
        });
    }
}
