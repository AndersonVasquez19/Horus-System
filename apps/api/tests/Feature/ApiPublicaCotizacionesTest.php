<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\FormularioCotizacionPublica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FichasDeServiciosParaPruebas;
use Tests\TestCase;

/**
 * Fase 2c.3: solicitud de cotización por el BFF (`/api/public/v1/cotizaciones*`). Entra por el módulo comercial
 * existente (`solicitudes_cotizacion` con `origen = publico`) y se gestiona en el panel (`/api/panel/v1/servicios/cotizaciones`). Transporte
 * simulado y SQLite en memoria: NO prueba PostgreSQL, concurrencia real ni correo. Las reglas internas del módulo
 * (estados, ETag, responsables) las cubre `Panel\CotizacionesContratoPanelTest`; aquí se prueba que lo recibido de la web las cumple.
 */
class ApiPublicaCotizacionesTest extends TestCase
{
    use FichasDeServiciosParaPruebas;
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private User $admin;

    /** @var array<string, string> codigo => identificador_publico */
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        Mail::fake();
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-servicios.enabled' => true, 'recepcion-cotizaciones.enabled' => true]);
        $this->admin = $this->adminUser();
        $this->actingAs($this->admin);
        // Cableado activo (línea con página propia), videovigilancia INACTIVA y un servicio genérico publicado.
        $this->servicio('CABLEADO', 'Servicio administrativo de cableado', true);
        $this->servicio('VIDEOVIGILANCIA', 'Servicio administrativo de cámaras', false);
        $this->servicio('GENERICO', 'Servicio administrativo genérico', true, 'Diagnóstico de redes');
        $this->servicio('BORRADOR', 'Servicio sin publicar', true, null);
        auth()->logout();
    }

    private function etag(int $id): string
    {
        return $this->revisionServicioPanel($id);
    }

    /** Crea el servicio; con `$titulo` además prepara y publica su ficha, y con `$activo` lo activa. */
    private function servicio(string $codigo, string $nombre, bool $activo, ?string $titulo = null): void
    {
        $id = $this->crearServicioPanel(['codigo' => $codigo, 'nombre' => $nombre, 'categoria' => 'Redes']);
        $this->uuids[$codigo] = (string) DB::table('servicios_tecnologicos')->where('id', $id)->value('identificador_publico');
        if ($titulo !== null) {
            $this->guardarFichaPanel($id, [
                'titulo' => $titulo, 'resumen' => 'Resumen público', 'descripcion' => 'Descripción pública.',
                'vigente_desde' => '2026-11-01', 'vigente_hasta' => '2026-12-31',
            ]);
        }
        if ($activo) {
            $this->cambiarEstadoServicioPanel($id, 'activo');
        }
        if ($titulo !== null && $activo && $codigo !== 'BORRADOR') {
            $this->autorizarFichaPanel($id);
        }
    }

    /** @return array<string, string> */
    private function bff(?string $visitante = self::VISITANTE): array
    {
        return ['X-Horus-Web-Token' => self::TOKEN] + ($visitante === null ? [] : ['X-Horus-Visitante' => $visitante]);
    }

    /** @return array<string, mixed> */
    private function formulario(): array
    {
        return $this->getJson('/api/public/v1/cotizaciones/formulario', $this->bff())->assertOk()->json();
    }

    private function marcaMadura(): string
    {
        $marca = $this->formulario()['marca'];
        $this->travel(5)->seconds();

        return $marca;
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = [], ?string $marca = null): array
    {
        return $extra + [
            'servicios' => [$this->uuids['CABLEADO']], 'nombre' => 'Cliente Ficticio', 'empresa' => 'Empresa Sintética SAC',
            'email' => 'cliente.ficticio@example.test', 'telefono' => '+51 900 000 000', 'ubicacion' => 'Cajamarca, sede norte',
            'necesidad' => 'Necesitamos cablear dos pisos de oficinas con 30 puntos de red.', 'informacion' => 'Visita posible los sábados.',
            'consentimiento' => true, 'marca' => $marca ?? $this->marcaMadura(),
        ];
    }

    private function enviar(array $cuerpo, ?string $visitante = self::VISITANTE): TestResponse
    {
        return $this->postJson('/api/public/v1/cotizaciones', $cuerpo, $this->bff($visitante));
    }

    /**
     * Bandeja del panel (/api/panel/v1/servicios/cotizaciones), como la llama el BFF. El usuario se fija con actingAs().
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    private function bandeja(string $metodo, string $ruta, array $datos = [], array $cabeceras = []): TestResponse
    {
        return $this->withHeaders($this->bff(null))->json($metodo, '/api/panel/v1/servicios/'.ltrim($ruta, '/'), $datos, $cabeceras);
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['solicitudes_cotizacion', 'cotizacion_historial', 'audit_logs']);
    }

    // --- Acceso y disponibilidad ---------------------------------------------------------------------------

    public function test_exige_el_token_de_servicio_en_ambas_rutas(): void
    {
        $this->getJson('/api/public/v1/cotizaciones/formulario')->assertUnauthorized();
        $this->postJson('/api/public/v1/cotizaciones', ['servicios' => []])->assertUnauthorized();
        $this->postJson('/api/public/v1/cotizaciones', [], ['X-Horus-Web-Token' => 'otro-token-otro-token-otro-token-otro!'])->assertUnauthorized();
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);

        config(['horus-web.service_token' => '']);
        $this->getJson('/api/public/v1/cotizaciones/formulario', $this->bff())->assertNotFound();
    }

    public function test_apagada_la_recepcion_no_emite_marca_y_el_envio_responde_503_sin_escribir(): void
    {
        $cuerpo = $this->cuerpo();
        config(['recepcion-cotizaciones.enabled' => false]);

        $this->getJson('/api/public/v1/cotizaciones/formulario', $this->bff())->assertOk()
            ->assertJson(['habilitado' => false, 'marca' => null, 'servicios' => []]);
        $antes = $this->conteos();
        $this->enviar($cuerpo)->assertStatus(503);
        $this->enviar([])->assertStatus(503);
        $this->assertSame($antes, $this->conteos());

        // Solo el booleano true la activa.
        config(['recepcion-cotizaciones.enabled' => 'true']);
        $this->enviar($cuerpo)->assertStatus(503);
    }

    public function test_el_formulario_ofrece_solo_servicios_activos_con_titulo_publico(): void
    {
        $r = $this->getJson('/api/public/v1/cotizaciones/formulario', $this->bff())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('habilitado', true)->assertJsonPath('max_servicios', 5);
        $servicios = collect($r->json('servicios'))->pluck('titulo', 'valor');

        $this->assertSame([
            $this->uuids['CABLEADO'] => 'Cableado estructurado',
            $this->uuids['GENERICO'] => 'Diagnóstico de redes',
        ], $servicios->all());
        $this->assertStringNotContainsString('administrativo', $r->getContent());
        $this->assertNotNull(FormularioCotizacionPublica::leer($this->envejecida($r->json('marca'))));
    }

    private function envejecida(string $marca): string
    {
        $this->travel(5)->seconds();

        return $marca;
    }

    // --- Alta ----------------------------------------------------------------------------------------------

    public function test_registro_correcto_entra_al_modulo_comercial_como_solicitud_recibida(): void
    {
        $r = $this->enviar($this->cuerpo())->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $solicitud = SolicitudCotizacion::sole();

        $this->assertSame(['codigo', 'recibida_at', 'repetida'], array_keys($r->json()));
        $this->assertSame($solicitud->codigo, $r->json('codigo'));
        $this->assertMatchesRegularExpression('/^SOL-[0-9A-F-]{36}$/', $r->json('codigo'));
        $this->assertFalse($r->json('repetida'));
        $this->assertStringNotContainsString('Ficticio', $r->getContent());
        // Es una solicitud: sin responsable, sin operador, con servicio asociado y estado inicial.
        $this->assertSame(['publico', 'recibida', 1, null, null, 0], [$solicitud->origen, $solicitud->estado, $solicitud->revision, $solicitud->responsable_id, $solicitud->creado_por, $solicitud->creador_ambito]);
        $this->assertSame($this->uuids['CABLEADO'], $solicitud->servicio_uuid);
        $this->assertSame('Servicio administrativo de cableado', $solicitud->servicio_nombre_historico);
        $this->assertSame(['Cliente Ficticio', 'cliente.ficticio@example.test', '+51 900 000 000'], [$solicitud->contacto_nombre, $solicitud->contacto_email, $solicitud->contacto_telefono]);
        $this->assertStringContainsString('Servicios de interés: Cableado estructurado', $solicitud->necesidad_cliente);
        $this->assertStringContainsString('Empresa: Empresa Sintética SAC', $solicitud->necesidad_cliente);
        $this->assertStringContainsString('Ubicación del proyecto: Cajamarca, sede norte', $solicitud->necesidad_cliente);
        $this->assertStringContainsString('30 puntos de red', $solicitud->necesidad_cliente);
        $this->assertStringContainsString('Visita posible los sábados.', $solicitud->necesidad_cliente);
        // Sin precios, documentos ni correos.
        $this->assertStringNotContainsString('S/', $solicitud->necesidad_cliente);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_varios_servicios_conservan_el_primero_como_asociado_y_todos_en_la_necesidad(): void
    {
        $this->enviar($this->cuerpo(['servicios' => [$this->uuids['GENERICO'], $this->uuids['CABLEADO']], 'empresa' => null, 'telefono' => null, 'informacion' => null]))->assertCreated();
        $solicitud = SolicitudCotizacion::sole();

        $this->assertSame($this->uuids['GENERICO'], $solicitud->servicio_uuid);
        $this->assertStringContainsString('Servicios de interés: Diagnóstico de redes; Cableado estructurado', $solicitud->necesidad_cliente);
        $this->assertStringNotContainsString('Empresa:', $solicitud->necesidad_cliente);
        $this->assertNull($solicitud->contacto_telefono);
    }

    public function test_historial_y_auditoria_sin_actor_sin_ip_y_sin_datos_personales(): void
    {
        $this->enviar($this->cuerpo())->assertCreated();
        $solicitud = SolicitudCotizacion::sole();

        $h = CotizacionHistorial::sole();
        $this->assertSame([$solicitud->id, 1, 'creada', null], [$h->solicitud_id, $h->revision, $h->evento, $h->actor_id]);
        $this->assertSame(['estado' => 'recibida', 'origen' => 'publico'], $h->metadatos);

        $audit = AuditLog::query()->where('action', 'cotizacion_creada')->sole();
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->ip_address);
        $this->assertSame(['revision' => 1, 'estado' => 'recibida', 'origen' => 'publico', 'servicios' => 1], $audit->changes);
        $json = AuditLog::all()->toJson().DB::table('cotizacion_historial')->get()->toJson();
        foreach (['Ficticio', 'cliente.ficticio', '900 000 000', 'Sintética', 'puntos de red', self::VISITANTE] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
    }

    // --- Validación ----------------------------------------------------------------------------------------

    public function test_servicios_invalidos_no_disponibles_o_en_exceso_se_rechazan_sin_escribir(): void
    {
        $cuerpo = $this->cuerpo();
        $antes = $this->conteos();

        foreach ([
            'inexistente' => [(string) Str::uuid()],
            'inactivo' => [$this->uuids['VIDEOVIGILANCIA']],
            'sin publicar' => [$this->uuids['BORRADOR']],
            'uno bueno y uno malo' => [$this->uuids['CABLEADO'], $this->uuids['VIDEOVIGILANCIA']],
        ] as $caso => $servicios) {
            $this->enviar([...$cuerpo, 'servicios' => $servicios])->assertStatus(422)->assertJsonValidationErrors('servicios');
        }
        $this->enviar([...$cuerpo, 'servicios' => []])->assertStatus(422)->assertJsonValidationErrors('servicios');
        $this->enviar([...$cuerpo, 'servicios' => 'texto'])->assertStatus(422)->assertJsonValidationErrors('servicios');
        $this->enviar([...$cuerpo, 'servicios' => ['no-es-uuid']])->assertStatus(422)->assertJsonValidationErrors('servicios.0');
        $this->enviar([...$cuerpo, 'servicios' => [$this->uuids['CABLEADO'], strtoupper($this->uuids['CABLEADO'])]])->assertStatus(422)->assertJsonValidationErrors('servicios.1');
        $this->enviar([...$cuerpo, 'servicios' => array_fill(0, 6, (string) Str::uuid())])->assertStatus(422)->assertJsonValidationErrors('servicios');
        $this->assertSame($antes, $this->conteos());
    }

    public function test_datos_incompletos_o_invalidos_responden_422_sin_escribir(): void
    {
        $cuerpo = $this->cuerpo();
        $antes = $this->conteos();

        $this->enviar([])->assertStatus(422)->assertJsonValidationErrors(['servicios', 'nombre', 'email', 'ubicacion', 'necesidad', 'consentimiento', 'marca']);
        foreach ([
            ['email', 'sin-correo', 'email'], ['email', 'a@@b.com', 'email'], ['nombre', 'A', 'nombre'], ['nombre', '<b>Hola</b>', 'nombre'],
            ['telefono', 'abc', 'telefono'], ['ubicacion', 'X', 'ubicacion'], ['necesidad', 'corta', 'necesidad'],
            ['necesidad', str_repeat('x', 3001), 'necesidad'], ['necesidad', '<script>alert(1)</script> necesidad larga', 'necesidad'],
            ['informacion', str_repeat('x', 1001), 'informacion'], ['empresa', str_repeat('x', 121), 'empresa'],
            ['consentimiento', false, 'consentimiento'], ['sitio_web', 'https://spam.test', 'sitio_web'],
        ] as [$campo, $valor, $error]) {
            $this->enviar([...$cuerpo, $campo => $valor])->assertStatus(422)->assertJsonValidationErrors($error);
        }
        $this->enviar([...$cuerpo, 'marca' => 'no-firmada'])->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->assertSame($antes, $this->conteos());
    }

    public function test_el_cliente_no_puede_fijar_precio_estado_responsable_ni_identificadores(): void
    {
        $cuerpo = $this->cuerpo();

        foreach (['precio' => 1, 'monto' => 10, 'total' => 5, 'estado' => 'en_revision', 'codigo' => 'SOL-X', 'identificador' => 'x', 'origen' => 'administrativo',
            'responsable_id' => 1, 'servicio_id' => 1, 'servicio_uuid' => (string) Str::uuid(), 'revision' => 9, 'creado_por' => 1] as $campo => $valor) {
            $this->enviar([...$cuerpo, $campo => $valor])->assertStatus(422)->assertJsonValidationErrors($campo);
        }
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }

    public function test_formulario_demasiado_rapido_o_caducado_se_rechaza(): void
    {
        $servicios = [$this->uuids['CABLEADO']];
        $marca = $this->formulario()['marca'];
        $datos = $this->cuerpo(['servicios' => $servicios], $marca);

        $this->enviar($datos)->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->travel(7201)->seconds();
        $this->enviar($datos)->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }

    // --- Duplicados e idempotencia -------------------------------------------------------------------------

    public function test_reenviar_el_mismo_formulario_devuelve_la_misma_solicitud_y_un_cambio_se_rechaza(): void
    {
        $cuerpo = $this->cuerpo();

        $primera = $this->enviar($cuerpo)->assertCreated();
        $this->enviar($cuerpo)->assertOk()->assertJson(['codigo' => $primera->json('codigo'), 'repetida' => true]);
        $this->enviar([...$cuerpo, 'necesidad' => 'Otra necesidad distinta, igualmente larga.'])->assertStatus(422)->assertJsonValidationErrors('marca');

        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizacion_historial', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'cotizacion_creada')->count());
    }

    public function test_el_mismo_contenido_en_otro_formulario_no_duplica_pero_una_necesidad_distinta_si_es_otra_solicitud(): void
    {
        $primera = $this->enviar($this->cuerpo())->assertCreated();

        $igual = $this->enviar($this->cuerpo(['email' => 'CLIENTE.Ficticio@Example.test', 'nombre' => 'CLIENTE FICTICIO']))->assertOk();
        $this->assertSame($primera->json('codigo'), $igual->json('codigo'));
        $this->assertTrue($igual->json('repetida'));
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);

        // Otra necesidad de la misma persona y servicio NO se descarta.
        $otra = $this->enviar($this->cuerpo(['necesidad' => 'Segundo proyecto: cámaras y rack en el almacén.']))->assertCreated();
        $this->assertNotSame($primera->json('codigo'), $otra->json('codigo'));
        $this->assertDatabaseCount('solicitudes_cotizacion', 2);
    }

    // --- Límites -------------------------------------------------------------------------------------------

    public function test_sexto_envio_valido_del_mismo_visitante_en_10_minutos_responde_429(): void
    {
        foreach (range(1, 5) as $n) {
            $this->enviar($this->cuerpo(['email' => "cliente{$n}@example.test"]))->assertCreated();
        }

        $r = $this->enviar($this->cuerpo(['email' => 'cliente6@example.test']))->assertStatus(429);
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        $this->assertSame('demasiadas_solicitudes', $r->json('error.code'));
        $this->assertDatabaseCount('solicitudes_cotizacion', 5);

        $this->enviar($this->cuerpo(['email' => 'cliente6@example.test']), str_repeat('b', 64))->assertCreated();
    }

    public function test_el_mismo_correo_no_supera_5_solicitudes_al_dia_aunque_cambie_de_visitante(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $letra) {
            $this->enviar($this->cuerpo(['necesidad' => "Necesidad número {$i} con texto suficiente."]), str_repeat($letra, 64))->assertCreated();
        }

        $this->enviar($this->cuerpo(['necesidad' => 'Necesidad sexta con texto suficiente.']), str_repeat('f', 64))->assertStatus(429);
        $this->assertDatabaseCount('solicitudes_cotizacion', 5);
    }

    public function test_reintentos_errores_y_rechazos_del_servicio_no_consumen_el_limite(): void
    {
        $cuerpo = $this->cuerpo();
        $this->enviar($cuerpo)->assertCreated();
        for ($i = 0; $i < 3; $i++) {
            $this->enviar($cuerpo)->assertOk()->assertJson(['repetida' => true]);
            $this->enviar([...$cuerpo, 'email' => 'mal'])->assertStatus(422);
            // Pasa el request pero el servicio lo rechaza: servicio que ya no existe.
            $this->enviar([...$cuerpo, 'servicios' => [(string) Str::uuid()]])->assertStatus(422)->assertJsonValidationErrors('servicios');
        }

        foreach (range(1, 4) as $n) {
            $this->enviar($this->cuerpo(['email' => "nuevo{$n}@example.test"]))->assertCreated();
        }
        $this->enviar($this->cuerpo(['email' => 'nuevo5@example.test']))->assertStatus(429);
    }

    public function test_rafagas_de_intentos_invalidos_se_frenan_por_visitante(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->enviar([])->assertStatus(422);
        }

        $this->enviar([])->assertStatus(429);
        $this->enviar([], 'no-es-un-hmac')->assertStatus(422);
    }

    // --- Sin consulta pública y recepción en el panel -------------------------------------------------------

    public function test_el_formulario_blade_de_cotizacion_ya_no_existe(): void
    {
        // Retirado en la Fase 1 del retiro de Blade: la solicitud solo entra por `/api/public/v1/cotizaciones`.
        $this->postJson('/solicitudes-cotizacion', $this->cuerpo())->assertNotFound();
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }
}
