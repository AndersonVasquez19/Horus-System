<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;

/**
 * Libro de Reclamaciones por la API del panel (`/api/panel/v1/compartido/reclamaciones`): mismos servicios, filtros y
 * constancias que el Blade. Contiene datos personales, así que se comprueba qué sale, qué no sale y que cada acceso
 * deja su constancia sin guardar el texto buscado. No envía correos: cola y correo simulados.
 */
final class ReclamacionesPanelTest extends PanelApiTestCase
{
    use PreparaReclamaciones;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: true);
    }

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->json($metodo, '/api/panel/v1/compartido/reclamaciones'.$ruta, $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function nueva(array $extra = []): Reclamacion
    {
        return $this->registrar($extra)['reclamacion'];
    }

    private function auditadas(string $accion): int
    {
        return AuditLog::query()->where('action', 'reclamacion_'.$accion)->count();
    }

    // ---------------------------------------------------------------- acceso

    public function test_exige_sesion_y_permisos_y_no_deja_constancia_si_se_deniega(): void
    {
        $r = $this->nueva();
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/reclamaciones')->assertUnauthorized();

        foreach (['coordinador', 'secretaria'] as $rol) {
            $usuario = $this->usuarioConPermisos($rol);
            $this->como($usuario, 'GET', '')->assertForbidden();
            $this->como($usuario, 'GET', "/{$r->identificador}")->assertForbidden();
            $this->como($usuario, 'GET', "/{$r->identificador}/hoja")->assertForbidden();
            $this->como($usuario, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'nota', 'nota' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, $this->auditadas('consultada') + $this->auditadas('impresa') + $this->auditadas('nota'));
    }

    public function test_quien_solo_gestiona_no_responde_ni_acepta_y_quien_solo_ve_no_gestiona(): void
    {
        $r = $this->nueva();
        $gestor = $this->usuarioConPermisos('coordinador', ['panel.academico.acceder', 'reclamaciones.ver', 'reclamaciones.gestionar']);
        $lector = $this->usuarioConPermisos('secretaria', ['panel.academico.acceder', 'reclamaciones.ver']);

        $this->como($gestor, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'nota', 'nota' => 'Revisado.'])->assertOk()->assertJsonPath('message', 'Nota interna registrada.');
        $this->como($gestor, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'carta', 'constancia' => 'Cargo 1'])->assertForbidden();
        $this->como($gestor, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'aceptacion', 'fecha_recepcion' => '2026-10-26', 'medio' => 'correo', 'constancia' => 'ok'])->assertForbidden();
        $this->como($lector, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'nota', 'nota' => 'No debería'])->assertForbidden();
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'eliminar'])->assertForbidden();

        $this->assertSame('abierto', $r->fresh()->estado);
        $this->assertSame(1, $this->auditadas('nota'));
    }

    public function test_uuid_malformado_o_inexistente_responde_404(): void
    {
        $this->como($this->admin, 'GET', '/no-es-uuid')->assertNotFound();
        $this->como($this->admin, 'GET', '/6f1b7f9e-2c1a-4b8e-9d1f-1a2b3c4d5e6f')->assertNotFound();
        $this->como($this->admin, 'GET', '/6f1b7f9e-2c1a-4b8e-9d1f-1a2b3c4d5e6f/hoja')->assertNotFound();
    }

    // ---------------------------------------------------------------- bandeja

    public function test_lista_filtra_busca_sin_comodines_y_calcula_metricas(): void
    {
        $this->nueva(['nombres' => 'Lucía', 'apellidos' => 'Quispe']);
        $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null, 'nombres' => 'Mario', 'apellidos' => 'Rojas']);

        $todas = $this->como($this->admin, 'GET', '')->assertOk();
        $this->assertCount(2, $todas->json('data'));
        $this->assertSame(['total' => 2, 'quejas' => 1, 'reclamos' => 1, 'abiertos' => 2], $todas->json('meta.metricas'));
        $this->assertStringContainsString('no-store', (string) $todas->headers->get('Cache-Control'));

        $this->assertSame(['Lucía Quispe'], $this->como($this->admin, 'GET', '?busqueda=quispe')->json('data.*.consumidor'));
        $this->assertSame(['Mario Rojas'], $this->como($this->admin, 'GET', '?tipo=queja')->json('data.*.consumidor'));
        $this->assertSame([], $this->como($this->admin, 'GET', '?busqueda=%25')->json('data'));
        $this->assertSame([], $this->como($this->admin, 'GET', '?estado=respondido')->json('data'));
    }

    public function test_el_rango_de_fechas_son_dias_completos_de_lima_y_se_valida(): void
    {
        $this->nueva();

        $this->assertCount(1, $this->como($this->admin, 'GET', '?desde=2026-10-26&hasta=2026-10-26')->assertOk()->json('data'));
        $this->assertCount(0, $this->como($this->admin, 'GET', '?desde=2026-10-27')->assertOk()->json('data'));
        $this->como($this->admin, 'GET', '?desde=26/10/2026')->assertUnprocessable()->assertJsonValidationErrors('desde');
        $this->como($this->admin, 'GET', '?desde=2026-10-27&hasta=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->como($this->admin, 'GET', '?tipo=otro')->assertUnprocessable();
    }

    public function test_el_listado_no_expone_datos_personales_extra_ni_el_id_interno(): void
    {
        $r = $this->nueva();

        $respuesta = $this->como($this->admin, 'GET', '')->assertOk();
        $crudo = $respuesta->getContent();
        foreach (['persona.ficticia@example.test', '12345678', 'Calle Ficticia', '900 000 000', 'token_envio', 'Detalle sintético'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }
        $this->assertSame($r->identificador, $respuesta->json('data.0.id'));
        $this->assertSame(['consumidor', 'estado', 'fecha_limite', 'id', 'numero', 'plazo_suspendido', 'recibida_local', 'tipo'], collect($respuesta->json('data.0'))->keys()->sort()->values()->all());
    }

    public function test_la_constancia_de_la_bandeja_guarda_los_nombres_de_los_filtros_y_nunca_su_valor(): void
    {
        $this->nueva(['nombres' => 'Lucía']);

        $this->como($this->admin, 'GET', '?busqueda=Lucía&tipo=reclamo')->assertOk();

        $fila = AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->latest('id')->firstOrFail();
        $json = json_encode($fila->changes, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('busqueda', $json);
        $this->assertStringNotContainsString('Lucía', $json);
    }

    // ---------------------------------------------------------------- expediente

    public function test_el_expediente_trae_el_detalle_y_deja_constancia_de_la_consulta(): void
    {
        $r = $this->nueva();

        $respuesta = $this->como($this->admin, 'GET', "/{$r->identificador}")->assertOk();
        $respuesta->assertJsonPath('data.id', $r->identificador)
            ->assertJsonPath('data.numero', $r->numero)
            ->assertJsonPath('data.consumidor.email', 'persona.ficticia@example.test')
            ->assertJsonPath('data.consumidor.representante', null)
            ->assertJsonPath('data.estado', 'abierto')
            ->assertJsonPath('meta.puede_gestionar', true)
            ->assertJsonPath('meta.correo_activo', false);
        $this->assertNotEmpty($respuesta->json('data.historial'));
        $this->assertContains($this->admin->id, collect($respuesta->json('meta.responsables'))->pluck('id')->all());

        $crudo = $respuesta->getContent();
        $this->assertStringNotContainsString('token_envio', $crudo);
        $this->assertArrayNotHasKey('token_envio', $respuesta->json('data'));
        $this->assertSame(1, $this->auditadas('consultada'));
    }

    public function test_los_envios_muestran_el_correo_enmascarado_y_sin_cuerpos(): void
    {
        $r = $this->nueva();
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->firstOrFail();
        $envio->forceFill(['estado' => 'fallido', 'intentos' => 2, 'error_codigo' => 'smtp'])->save();

        $respuesta = $this->como($this->admin, 'GET', "/{$r->identificador}")->assertOk();

        $primero = $respuesta->json('data.envios.0');
        $this->assertSame('pe•••@example.test', $primero['destinatario']);
        $this->assertSame('Fallido', $primero['estado_etiqueta']);
        $this->assertTrue($primero['reintentable']);
        $this->assertSame('smtp', $primero['error_codigo']);
        $this->assertStringNotContainsString('persona.ficticia', json_encode($respuesta->json('data.envios')));
        $this->assertArrayNotHasKey('cuerpo', $primero);
    }

    public function test_el_menor_de_edad_expone_a_su_representante(): void
    {
        $r = $this->nueva([
            'es_menor' => true, 'representante_nombre' => 'Madre Ficticia', 'representante_domicilio' => 'Av. Ficticia 1',
            'representante_telefono' => '+51 911 111 111', 'representante_email' => 'madre@example.test',
        ]);

        $this->como($this->admin, 'GET', "/{$r->identificador}")->assertOk()
            ->assertJsonPath('data.consumidor.es_menor', true)
            ->assertJsonPath('data.consumidor.representante.nombre', 'Madre Ficticia');
    }

    // ---------------------------------------------------------------- hoja

    public function test_la_hoja_trae_el_texto_oficial_y_deja_su_propia_constancia(): void
    {
        $r = $this->nueva();

        $respuesta = $this->como($this->admin, 'GET', "/{$r->identificador}/hoja")->assertOk();
        $respuesta->assertJsonPath('data.numero', $r->numero)->assertJsonPath('data.canal_etiqueta', 'Libro virtual')->assertJsonPath('data.respuesta', null);
        $this->assertStringContainsString('HOJA DE RECLAMACIÓN', $respuesta->json('data.texto_oficial'));
        $this->assertStringContainsString($r->numero, $respuesta->json('data.texto_oficial'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertSame(1, $this->auditadas('impresa'));
        $this->assertSame(0, $this->auditadas('consultada'));
    }

    // ---------------------------------------------------------------- gestión

    public function test_una_respuesta_formal_por_carta_cierra_el_caso_y_un_segundo_intento_da_409(): void
    {
        $r = $this->nueva();
        $datos = ['operacion' => 'respuesta', 'respuesta' => 'Respuesta formal.', 'canal' => 'carta', 'constancia' => 'Cargo 1'];

        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", $datos)->assertOk()->assertJsonPath('message', 'Respuesta formal registrada.');
        $this->assertSame('respondido', $r->fresh()->estado);

        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", $datos)->assertStatus(409)->assertJsonStructure(['message']);
        $this->como($this->admin, 'GET', "/{$r->identificador}/hoja")->assertOk()->assertJsonPath('data.respuesta', 'Respuesta formal.');
    }

    public function test_una_propuesta_a_una_queja_responde_409_con_el_mensaje_fijo(): void
    {
        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);

        $this->como($this->admin, 'POST', "/{$queja->identificador}/gestion", ['operacion' => 'propuesta', 'asunto' => 'A', 'cuerpo' => 'B'])
            ->assertStatus(409)->assertJson(['message' => 'Las propuestas de solución solo aplican a reclamos.']);
    }

    public function test_asigna_responsable_solo_del_personal_con_permiso(): void
    {
        $r = $this->nueva();
        $ajeno = User::factory()->create();

        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'responsable', 'responsable_id' => $ajeno->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsable_id');
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'responsable', 'responsable_id' => $this->admin->id])->assertOk();
        $this->como($this->admin, 'GET', "/{$r->identificador}")->assertJsonPath('data.responsable.id', $this->admin->id);
    }

    public function test_valida_los_campos_de_cada_operacion(): void
    {
        $r = $this->nueva();

        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'nota'])->assertUnprocessable()->assertJsonValidationErrors('nota');
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'carta'])
            ->assertUnprocessable()->assertJsonValidationErrors('constancia');
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'rechazo', 'fecha_recepcion' => '26/10/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_recepcion');
    }

    public function test_con_el_correo_activo_enviar_exige_confirmacion_y_el_reintento_es_solo_de_su_reclamacion(): void
    {
        $r = $this->nueva();
        $otra = $this->nueva();
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->firstOrFail();
        $envio->forceFill(['estado' => 'fallido', 'intentos' => 4, 'error_codigo' => 'smtp'])->save();

        // Un envío ajeno responde 404 sin tocar nada.
        $this->como($this->admin, 'POST', "/{$otra->identificador}/gestion", ['operacion' => 'reintento', 'envio_id' => $envio->id])->assertNotFound();
        $this->assertSame('fallido', $envio->fresh()->estado);

        $this->habilitarReclamaciones(envio: true);
        Queue::fake();
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'reintento', 'envio_id' => $envio->id])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmar_envio');
        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'correo'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmar_envio');
        Queue::assertNothingPushed();

        $this->como($this->admin, 'POST', "/{$r->identificador}/gestion", ['operacion' => 'reintento', 'envio_id' => $envio->id, 'confirmar_envio' => true])
            ->assertOk()->assertJsonPath('message', 'Envío reprogramado.');
    }

    public function test_filtra_por_canal_responsable_y_plazo_y_expone_metricas_de_plazo(): void
    {
        $vencida = $this->nueva();
        $vencida->forceFill([
            'fecha_limite' => \App\Support\HoraLocal::ahora()->subDays(2)->toDateString(),
            'responsable_id' => null,
        ])->save();

        $respaldo = $this->nueva();
        \Illuminate\Support\Facades\DB::table('reclamaciones')->where('id', $respaldo->id)->update([
            'canal' => 'respaldo',
            'numero_hoja_respaldo' => 'HR-001',
        ]);
        $respaldo->forceFill([
            'fecha_limite' => \App\Support\HoraLocal::ahora()->addDays(10)->toDateString(),
            'responsable_id' => $this->admin->id,
        ])->save();

        $this->como($this->admin, 'GET', '?canal=respaldo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $respaldo->identificador)
            ->assertJsonPath('meta.plazos.vencidas', 1);

        $this->como($this->admin, 'GET', "?responsable={$this->admin->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $respaldo->identificador);

        $this->como($this->admin, 'GET', '?responsable=sin_asignar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vencida->identificador);

        $this->como($this->admin, 'GET', '?plazo=vencidas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vencida->identificador);
    }
}
