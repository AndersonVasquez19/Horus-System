<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SolicitudCotizacionPublicaController;
use App\Http\Middleware\RecepcionCotizacionesHabilitada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecepcionCotizacionesFlagTest extends TestCase
{
    use RefreshDatabase;

    private const MARCADOR = 'MARCADOR-SINTETICO-19-2-F1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    /** @return array<string, string> */
    private function cuerpo(): array
    {
        return ['contacto_nombre' => self::MARCADOR, 'contacto_email' => 'ficticio@example.test',
            'necesidad_cliente' => self::MARCADOR, 'estado' => 'en_revision', 'responsable_id' => '1'];
    }

    private function conteos(): array
    {
        return [DB::table('solicitudes_cotizacion')->count(), DB::table('cotizacion_historial')->count(), DB::table('audit_logs')->count()];
    }

    public function test_flag_apagado_por_defecto(): void
    {
        $this->assertFalse(config('recepcion-cotizaciones.enabled'));
    }

    public function test_solo_dos_rutas_publicas_nuevas_con_el_middleware_de_flag(): void
    {
        $nuevas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_contains($ruta->uri(), 'solicitar-cotizacion') || str_contains($ruta->uri(), 'solicitudes-cotizacion'));
        $this->assertCount(2, $nuevas);

        $show = app('router')->getRoutes()->getByName('cotizacion-publica.show');
        $store = app('router')->getRoutes()->getByName('cotizacion-publica.store');
        $this->assertSame(['GET', 'HEAD'], $show->methods());
        $this->assertSame(['POST'], $store->methods());
        $this->assertSame('solicitar-cotizacion', $show->uri());
        $this->assertSame('solicitudes-cotizacion', $store->uri());
        $this->assertSame(SolicitudCotizacionPublicaController::class.'@show', $show->getActionName());
        $this->assertSame(SolicitudCotizacionPublicaController::class.'@store', $store->getActionName());
        foreach ([$show, $store] as $ruta) {
            $this->assertSame([], $ruta->parameterNames());
            $this->assertContains(RecepcionCotizacionesHabilitada::class, $ruta->gatherMiddleware());
            $this->assertNotContains('auth', $ruta->gatherMiddleware());
        }
    }

    public function test_apagado_get_responde_404_sin_consultas(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/solicitar-cotizacion')->assertNotFound();
        $this->get('/solicitar-cotizacion', ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_apagado_post_responde_404_sin_validar_ni_guardar_ni_reflejar_la_entrada(): void
    {
        $antes = $this->conteos();
        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ([[], ['Accept' => 'application/json'], ['Accept' => 'text/html']] as $cabeceras) {
            $respuesta = $this->post('/solicitudes-cotizacion', $this->cuerpo(), $cabeceras);
            $respuesta->assertNotFound();
            $this->assertStringNotContainsString(self::MARCADOR, $respuesta->getContent());
        }
        // Cuerpo inválido: sigue siendo 404, no 422; no se evalúa ningún campo comercial.
        $this->post('/solicitudes-cotizacion', ['contacto_nombre' => ['x']], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($antes, $this->conteos());
        $this->assertNull(session()->get('_old_input'));
        $this->assertSame([], session()->all()['errors'] ?? []);
    }

    public function test_apagado_un_admin_autenticado_tampoco_crea_ni_audita_nada(): void
    {
        $admin = $this->adminUser();
        $antes = $this->conteos();

        $this->actingAs($admin)->post('/solicitudes-cotizacion', $this->cuerpo())->assertNotFound();
        $this->actingAs($admin)->get('/solicitar-cotizacion')->assertNotFound();

        $this->assertSame($antes, $this->conteos());
    }

    /** @return array<string, array{mixed}> */
    public static function valoresNoEstrictos(): array
    {
        return ['cadena true' => ['true'], 'cadena 1' => ['1'], 'entero 1' => [1], 'cadena on' => ['on'], 'arreglo' => [[true]]];
    }

    #[DataProvider('valoresNoEstrictos')]
    public function test_solo_el_booleano_true_habilita(mixed $valor): void
    {
        config(['recepcion-cotizaciones.enabled' => $valor]);

        $this->get('/solicitar-cotizacion')->assertNotFound();
        $this->post('/solicitudes-cotizacion', $this->cuerpo())->assertNotFound();
    }

    public function test_encendido_la_fase_1_responde_503_generico_sin_leer_ni_guardar(): void
    {
        config(['recepcion-cotizaciones.enabled' => true]);
        $antes = $this->conteos();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $get = $this->get('/solicitar-cotizacion');
        $post = $this->post('/solicitudes-cotizacion', $this->cuerpo(), ['Accept' => 'application/json']);

        foreach ([$get, $post] as $respuesta) {
            $respuesta->assertStatus(503)->assertExactJson(['mensaje' => 'La recepción de solicitudes no está disponible.']);
            $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
            $this->assertStringNotContainsString(self::MARCADOR, $respuesta->getContent());
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($antes, $this->conteos());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_el_resto_del_sitio_y_la_api_privada_no_cambian(): void
    {
        $this->get('/contactos')->assertOk();
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('contactos')->methods());
        $this->getJson('/admin/cotizaciones')->assertUnauthorized();
        $this->assertCount(8, collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'admin/cotizaciones')));
    }
}
