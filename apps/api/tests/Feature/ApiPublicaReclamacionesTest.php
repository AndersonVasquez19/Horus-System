<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Support\AntispamReclamaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * Fase 2c.1: Libro de Reclamaciones por el BFF (`/api/public/v1/reclamaciones*`). Reutiliza el módulo existente, así
 * que aquí se prueba el contrato del endpoint (token de servicio, forma de la respuesta, límites por visitante,
 * sin consulta pública) y no se repite la batería de reglas de negocio de `GestionReclamacionesTest`.
 * Transporte simulado y SQLite en memoria: no prueba PostgreSQL ni correo real.
 */
class ApiPublicaReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: false);
        config(['horus-web.service_token' => self::TOKEN]);
    }

    /** @return array<string, string> */
    private function bff(?string $visitante = self::VISITANTE): array
    {
        return ['X-Horus-Web-Token' => self::TOKEN] + ($visitante === null ? [] : ['X-Horus-Visitante' => $visitante]);
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = []): array
    {
        return $extra + $this->datosReclamacion() + [
            'marca' => AntispamReclamaciones::emitirMarca(time() - 30),
            'token_envio' => (string) Str::uuid(),
            'acepta_privacidad' => true,
        ];
    }

    private function enviar(array $cuerpo, ?string $visitante = self::VISITANTE): TestResponse
    {
        return $this->postJson('/api/public/v1/reclamaciones', $cuerpo, $this->bff($visitante));
    }

    /** @return list<int> */
    private function conteos(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['reclamaciones', 'reclamacion_historial', 'reclamacion_envios', 'audit_logs']);
    }

    // --- Acceso y estado del libro ------------------------------------------------------------------------

    public function test_exige_el_token_de_servicio_en_ambas_rutas(): void
    {
        $this->habilitarReclamaciones();
        $this->getJson('/api/public/v1/reclamaciones/formulario')->assertUnauthorized();
        $this->postJson('/api/public/v1/reclamaciones', $this->cuerpo())->assertUnauthorized();
        $this->postJson('/api/public/v1/reclamaciones', $this->cuerpo(), ['X-Horus-Web-Token' => 'otro-token-otro-token-otro-token-otro!'])->assertUnauthorized();
        $this->assertSame(0, Reclamacion::query()->count());

        config(['horus-web.service_token' => '']);
        $this->getJson('/api/public/v1/reclamaciones/formulario', $this->bff())->assertNotFound();
    }

    public function test_apagado_el_formulario_no_emite_marca_y_el_envio_responde_404_sin_escribir(): void
    {
        $this->getJson('/api/public/v1/reclamaciones/formulario', $this->bff())->assertOk()
            ->assertJson(['habilitado' => false, 'marca' => null, 'token_envio' => null, 'correo_activo' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
        $antes = $this->conteos();

        $this->enviar($this->cuerpo())->assertNotFound();
        $this->enviar([])->assertNotFound();

        $this->assertSame($antes, $this->conteos());
    }

    public function test_encendido_el_formulario_entrega_marca_firmada_y_token_uuid(): void
    {
        $this->habilitarReclamaciones();
        $r = $this->getJson('/api/public/v1/reclamaciones/formulario', $this->bff())->assertOk()
            ->assertJson(['habilitado' => true, 'correo_activo' => true, 'plazo_dias_habiles' => 15, 'honeypot' => 'sitio_web']);

        $this->assertTrue(Str::isUuid($r->json('token_envio')));
        $this->assertTrue(AntispamReclamaciones::marcaValida($r->json('marca'), time() + 30));
        $this->assertFalse(AntispamReclamaciones::marcaValida($r->json('marca')), 'Recién emitida no alcanza el mínimo de segundos');
    }

    // --- Alta ---------------------------------------------------------------------------------------------

    public function test_reclamo_se_registra_y_la_respuesta_solo_lleva_numero_tipo_y_fecha_limite(): void
    {
        $this->habilitarReclamaciones();

        $r = $this->enviar($this->cuerpo())->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['numero' => '000000001-2026', 'tipo' => 'reclamo', 'fecha_limite' => '2026-11-16', 'repetida' => false, 'correo_activo' => true]);

        $this->assertStringNotContainsString('Ficticia', $r->getContent());
        $this->assertStringNotContainsString('persona.ficticia', $r->getContent());
        $fila = Reclamacion::query()->sole();
        $this->assertSame(['reclamo', 'web', 'abierto', 'servicio'], [$fila->tipo, $fila->canal, $fila->estado, $fila->bien_tipo]);
        // Sin clasificar por dominio: el módulo no tiene ni se le asigna responsable ni dominio al recibirla.
        $this->assertNull($fila->responsable_id);
        $this->assertSame(config('reclamaciones.privacidad_version'), $fila->privacidad_version);
    }

    public function test_queja_se_registra_sin_bien_ni_monto(): void
    {
        $this->habilitarReclamaciones();

        $this->enviar($this->cuerpo(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]))
            ->assertCreated()->assertJson(['tipo' => 'queja']);

        $fila = Reclamacion::query()->sole();
        $this->assertNull($fila->bien_tipo);
        $this->assertNull($fila->monto_bien);
    }

    public function test_la_auditoria_no_guarda_ip_ni_datos_del_consumidor(): void
    {
        $this->habilitarReclamaciones();
        $this->enviar($this->cuerpo())->assertCreated();

        $audit = AuditLog::query()->where('action', 'reclamacion_registrada')->sole();
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->ip_address);
        $this->assertSame(['esquema' => 1, 'numero' => '000000001-2026', 'canal' => 'web', 'tipo' => 'reclamo'], $audit->changes);
        $this->assertStringNotContainsString(self::VISITANTE, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_reenviar_el_mismo_token_devuelve_la_misma_hoja_sin_duplicar(): void
    {
        $this->habilitarReclamaciones();
        $cuerpo = $this->cuerpo();

        $this->enviar($cuerpo)->assertCreated()->assertJson(['repetida' => false]);
        $this->enviar($cuerpo)->assertOk()->assertJson(['numero' => '000000001-2026', 'repetida' => true]);

        $this->assertSame(1, Reclamacion::query()->count());
        $this->assertSame(1, DB::table('reclamacion_envios')->count());
    }

    public function test_datos_obligatorios_e_invalidos_responden_422_sin_escribir(): void
    {
        $this->habilitarReclamaciones();
        $antes = $this->conteos();

        $this->enviar([])->assertStatus(422)->assertJsonValidationErrors(['tipo', 'nombres', 'email', 'detalle', 'pedido', 'acepta_privacidad']);
        $this->enviar($this->cuerpo(['email' => 'no-es-correo']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->enviar($this->cuerpo(['tipo' => 'sugerencia']))->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->enviar($this->cuerpo(['numero_documento' => '123']))->assertStatus(422)->assertJsonValidationErrors('numero_documento');
        $this->enviar($this->cuerpo(['detalle' => 'corto']))->assertStatus(422)->assertJsonValidationErrors('detalle');
        $this->enviar($this->cuerpo(['detalle' => '<script>alert(1)</script> hechos suficientes']))->assertStatus(422)->assertJsonValidationErrors('detalle');
        $this->enviar($this->cuerpo(['es_menor' => true]))->assertStatus(422)->assertJsonValidationErrors(['representante_nombre', 'representante_email']);
        $this->enviar($this->cuerpo(['bien_tipo' => null, 'bien_descripcion' => null]))->assertStatus(422)->assertJsonValidationErrors('bien_tipo');

        $this->assertSame($antes, $this->conteos());
    }

    public function test_exige_la_declaracion_de_privacidad_y_la_marca_de_tiempo(): void
    {
        $this->habilitarReclamaciones();

        $this->enviar($this->cuerpo(['acepta_privacidad' => false]))->assertStatus(422)->assertJsonValidationErrors('acepta_privacidad');
        $this->enviar($this->cuerpo(['marca' => AntispamReclamaciones::emitirMarca()]))->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->enviar($this->cuerpo(['marca' => AntispamReclamaciones::emitirMarca(time() - 9000)]))->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->enviar($this->cuerpo(['sitio_web' => 'https://spam.test']))->assertStatus(422)->assertJsonValidationErrors('sitio_web');
        $this->assertSame(0, Reclamacion::query()->count());
    }

    public function test_el_cliente_no_puede_fijar_campos_que_asigna_el_servidor(): void
    {
        $this->habilitarReclamaciones();

        foreach (['estado' => 'cerrado', 'numero' => 'X-1', 'responsable_id' => 1, 'fecha_limite' => '2030-01-01', 'respuesta' => 'ok', 'canal' => 'respaldo'] as $campo => $valor) {
            $this->enviar($this->cuerpo([$campo => $valor]))->assertStatus(422)->assertJsonValidationErrors($campo);
        }
        $this->assertSame(0, Reclamacion::query()->count());
    }

    // --- Límites ------------------------------------------------------------------------------------------

    public function test_cuarto_envio_valido_del_mismo_visitante_en_10_minutos_responde_429(): void
    {
        $this->habilitarReclamaciones();
        foreach (['uno', 'dos', 'tres'] as $n) {
            $this->enviar($this->cuerpo(['email' => "{$n}@example.test"]))->assertCreated();
        }

        $r = $this->enviar($this->cuerpo(['email' => 'cuatro@example.test']))->assertStatus(429);
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        $this->assertSame('demasiadas_solicitudes', $r->json('error.code'));
        $this->assertSame(3, Reclamacion::query()->count());

        // Otro visitante no se ve afectado por el límite del primero.
        $this->enviar($this->cuerpo(['email' => 'cuatro@example.test']), str_repeat('b', 64))->assertCreated();
    }

    public function test_el_mismo_correo_no_puede_enviar_mas_de_tres_hojas_al_dia_aunque_cambie_de_visitante(): void
    {
        $this->habilitarReclamaciones();
        foreach (['a', 'b', 'c'] as $letra) {
            $this->enviar($this->cuerpo(), str_repeat($letra, 64))->assertCreated();
        }

        $this->enviar($this->cuerpo(), str_repeat('d', 64))->assertStatus(429);
        $this->assertSame(3, Reclamacion::query()->count());
    }

    public function test_reintentar_el_mismo_envio_no_consume_el_limite(): void
    {
        $this->habilitarReclamaciones();
        $cuerpo = $this->cuerpo();
        $this->enviar($cuerpo)->assertCreated();
        for ($i = 0; $i < 6; $i++) {
            $this->enviar($cuerpo)->assertOk()->assertJson(['repetida' => true]);
        }

        // Quedan 2 envíos nuevos del visitante: la hoja original fue la única que consumió cupo.
        $this->enviar($this->cuerpo(['email' => 'otra@example.test']))->assertCreated();
        $this->enviar($this->cuerpo(['email' => 'otra2@example.test']))->assertCreated();
        $this->enviar($this->cuerpo(['email' => 'otra3@example.test']))->assertStatus(429);
        $this->assertSame(3, Reclamacion::query()->count());
    }

    public function test_equivocarse_al_rellenar_no_consume_el_limite_de_envio(): void
    {
        $this->habilitarReclamaciones();
        for ($i = 0; $i < 5; $i++) {
            $this->enviar($this->cuerpo(['email' => 'mal']))->assertStatus(422);
        }

        $this->enviar($this->cuerpo())->assertCreated();
    }

    public function test_rafagas_de_intentos_invalidos_se_frenan_por_visitante(): void
    {
        $this->habilitarReclamaciones();
        for ($i = 0; $i < 20; $i++) {
            $this->enviar([])->assertStatus(422);
        }

        $this->enviar([])->assertStatus(429);
        // Un visitante con identificador malformado cae en el cubo único «anonimo», distinto del anterior.
        $this->enviar([], 'no-es-un-hmac')->assertStatus(422);
    }

    // --- Sin consulta pública, Blade intacto ---------------------------------------------------------------

    public function test_no_existe_ninguna_ruta_publica_para_consultar_reclamaciones(): void
    {
        $this->habilitarReclamaciones();
        $r = $this->enviar($this->cuerpo())->assertCreated();
        $fila = Reclamacion::query()->sole();

        foreach (['/api/public/v1/reclamaciones', '/api/public/v1/reclamaciones/'.$fila->identificador, '/api/public/v1/reclamaciones/'.$r->json('numero')] as $ruta) {
            $this->assertContains($this->getJson($ruta, $this->bff())->getStatusCode(), [404, 405], $ruta);
        }
        $this->assertContains($this->getJson('/api/public/v1/reclamaciones/'.$fila->identificador)->getStatusCode(), [401, 404]);
    }

    public function test_el_alta_del_sitio_blade_ya_no_existe(): void
    {
        $this->habilitarReclamaciones();

        // Retirada en la Fase 1 del retiro de Blade: el libro solo recibe reclamaciones por `/api/public/v1/reclamaciones`.
        $this->postJson('/libro-reclamaciones', $this->datosReclamacion() + [
            'marca' => AntispamReclamaciones::emitirMarca(time() - 30), 'token_envio' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('reclamaciones', 0);
    }
}
