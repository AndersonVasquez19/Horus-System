<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Jobs\EnviarReclamacionCorreo;
use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;

/**
 * E4 — Gestión de reclamaciones por la API del panel (`POST /api/panel/v1/compartido/reclamaciones/{uuid}/gestion`), SQLite en
 * memoria. Sustituye a las pruebas de `admin.reclamaciones.gestionar`, retirada de Blade el 14/11/2026.
 * El servicio ya tiene sus pruebas de reglas (GestionReclamacionesTest); aquí se mide la capa HTTP: acceso,
 * despacho a la operación correcta, errores 422/409 en JSON y confirmación del correo real.
 * No mide bloqueos ni concurrencia de PostgreSQL. No envía correos: la cola y el correo están simulados.
 */
final class ReclamacionesGestionPanelTest extends PanelApiTestCase
{
    use PreparaReclamaciones;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: true);
    }

    private function nueva(array $extra = []): Reclamacion
    {
        return $this->registrar($extra)['reclamacion'];
    }

    /** @param array<string, mixed> $datos */
    private function gestionar(User $usuario, Reclamacion $r, array $datos): TestResponse
    {
        return $this->actingAs($usuario)
            ->json('POST', '/api/panel/v1/compartido/reclamaciones/'.$r->identificador.'/gestion', $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function auditado(string $accion, Reclamacion $r): bool
    {
        return AuditLog::query()->where('action', 'reclamacion_'.$accion)->where('model_id', $r->id)->exists();
    }

    private function conPropuesta(Reclamacion $r): Reclamacion
    {
        $this->gestionar($this->admin, $r, ['operacion' => 'propuesta', 'asunto' => 'Propuesta', 'cuerpo' => 'Texto de la propuesta.'])
            ->assertOk();

        return $r->fresh();
    }

    // --- Acceso ------------------------------------------------------------------------------------------------

    public function test_el_visitante_recibe_401_y_el_personal_sin_permiso_403(): void
    {
        $r = $this->nueva();
        $datos = ['operacion' => 'nota', 'nota' => 'Nota'];

        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->postJson('/api/panel/v1/compartido/reclamaciones/'.$r->identificador.'/gestion', $datos)->assertUnauthorized();

        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->gestionar($this->usuarioConPermisos($rol), $r, $datos)->assertForbidden();
        }
        $this->gestionar(User::factory()->create(), $r, $datos)->assertForbidden();
        $this->assertFalse($this->auditado('nota', $r));
    }

    public function test_quien_solo_gestiona_no_puede_responder_ni_aceptar(): void
    {
        $gestor = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver', 'reclamaciones.gestionar']);
        $r = $this->nueva();

        $this->gestionar($gestor, $r, ['operacion' => 'nota', 'nota' => 'Revisado por coordinación.'])
            ->assertOk();
        $this->assertTrue($this->auditado('nota', $r));

        $this->gestionar($gestor, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'carta', 'constancia' => 'Cargo 1'])
            ->assertForbidden();
        $this->gestionar($gestor, $r, ['operacion' => 'aceptacion', 'fecha_recepcion' => '2026-10-26', 'medio' => 'correo', 'constancia' => 'Acepto'])
            ->assertForbidden();
        $this->assertSame('abierto', $r->fresh()->estado);
    }

    public function test_operacion_desconocida_o_ausente_responde_403(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'eliminar'])->assertForbidden();
        $this->gestionar($this->admin, $r, [])->assertForbidden();
    }

    public function test_uuid_malformado_o_inexistente_responde_404_y_get_responde_405(): void
    {
        $base = '/api/panel/v1/compartido/reclamaciones/';
        $cabeceras = ['X-Horus-Web-Token' => self::TOKEN];
        $this->actingAs($this->admin)->json('POST', $base.'no-es-uuid/gestion', ['operacion' => 'nota', 'nota' => 'x'], $cabeceras)->assertNotFound();
        $this->actingAs($this->admin)->json('POST', $base.'6f1b7f9e-2c1a-4b8e-9d1f-1a2b3c4d5e6f/gestion', ['operacion' => 'nota', 'nota' => 'x'], $cabeceras)->assertNotFound();

        $r = $this->nueva();
        $this->actingAs($this->admin)->json('GET', $base.$r->identificador.'/gestion', [], $cabeceras)->assertStatus(405);
    }

    // --- Operaciones -------------------------------------------------------------------------------------------

    public function test_asignar_y_quitar_responsable(): void
    {
        $r = $this->nueva();
        $otro = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => $otro->id])
            ->assertOk()
            ->assertJsonPath('message', 'Responsable actualizado.');
        $this->assertSame($otro->id, $r->fresh()->responsable_id);
        $this->assertTrue($this->auditado('responsable', $r));

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => null])
            ->assertOk();
        $this->assertNull($r->fresh()->responsable_id);
    }

    public function test_no_se_asigna_a_quien_no_puede_ver_reclamaciones(): void
    {
        $r = $this->nueva();
        $sinPermiso = $this->usuarioConPermisos('secretaria');

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => $sinPermiso->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsable_id');
        $this->assertNull($r->fresh()->responsable_id);
    }

    public function test_reclasificar_y_rechazo_de_reclamo_sin_bien(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'tipo', 'tipo' => 'queja'])
            ->assertOk();
        $this->assertSame('queja', $r->fresh()->tipo);
        $this->assertTrue($this->auditado('tipo', $r));

        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);
        $this->gestionar($this->admin, $queja, ['operacion' => 'tipo', 'tipo' => 'reclamo'])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->assertSame('queja', $queja->fresh()->tipo);
    }

    public function test_nota_queda_en_el_historial_tal_cual_se_escribio(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'nota', 'nota' => '<script>alert(1)</script> revisar'])
            ->assertOk();

        $this->assertDatabaseHas('reclamacion_historial', ['reclamacion_id' => $r->id, 'evento' => 'nota_interna']);
        // La API devuelve el texto sin tocar; el escapado al mostrarlo es de React (no hay HTML en la respuesta).
        $notas = $this->actingAs($this->admin)
            ->json('GET', '/api/panel/v1/compartido/reclamaciones/'.$r->identificador, [], ['X-Horus-Web-Token' => self::TOKEN])
            ->assertOk()->json('data.historial.*.nota');
        $this->assertContains('<script>alert(1)</script> revisar', $notas);
    }

    public function test_propuesta_suspende_el_plazo_y_no_despacha_con_el_correo_apagado(): void
    {
        $r = $this->conPropuesta($this->nueva());

        $this->assertNotNull($r->suspension_desde);
        $this->assertDatabaseHas('reclamacion_envios', ['reclamacion_id' => $r->id, 'tipo' => 'propuesta', 'estado' => 'pendiente']);
        $this->assertTrue($this->auditado('propuesta', $r));
        Queue::assertNotPushed(EnviarReclamacionCorreo::class);
    }

    public function test_propuesta_en_una_queja_vuelve_con_el_error_del_servicio_sin_escribir(): void
    {
        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);

        $this->gestionar($this->admin, $queja, ['operacion' => 'propuesta', 'asunto' => 'A', 'cuerpo' => 'B'])
            ->assertStatus(409)->assertJson(['message' => 'Las propuestas de solución solo aplican a reclamos.']);

        $this->assertNull($queja->fresh()->suspension_desde);
        // El alta pública ya deja la copia al consumidor; lo que no debe existir es un envío de propuesta.
        $this->assertSame(0, ReclamacionEnvio::query()->where('reclamacion_id', $queja->id)->where('tipo', 'propuesta')->count());
        $this->assertFalse($this->auditado('propuesta', $queja));
    }

    public function test_rechazo_reanuda_el_plazo(): void
    {
        $r = $this->conPropuesta($this->nueva());
        $limiteAntes = $r->fecha_limite->toDateString();

        $this->gestionar($this->admin, $r, ['operacion' => 'rechazo', 'fecha_recepcion' => $r->suspension_desde->toDateString()])
            ->assertOk();

        $r->refresh();
        $this->assertNull($r->suspension_desde);
        $this->assertSame($limiteAntes, $r->fecha_limite->toDateString(), 'Rechazada el mismo día: cero días suspendidos');
        $this->assertTrue($this->auditado('propuesta_rechazada', $r));
    }

    public function test_aceptacion_fuera_de_la_ventana_vuelve_con_error_y_dentro_concluye(): void
    {
        $r = $this->conPropuesta($this->nueva());
        $datos = ['operacion' => 'aceptacion', 'medio' => 'correo', 'constancia' => 'Acepto la propuesta.'];

        $this->gestionar($this->admin, $r, $datos + ['fecha_recepcion' => '2026-10-01'])
            ->assertStatus(409)->assertJson(['message' => 'La fecha de recepción está fuera de la ventana de la propuesta.']);
        $this->assertSame('abierto', $r->fresh()->estado);

        $this->gestionar($this->admin, $r, $datos + ['fecha_recepcion' => $r->suspension_desde->toDateString()])
            ->assertOk();
        $this->assertSame('concluido_por_acuerdo', $r->fresh()->estado);
        $this->assertTrue($this->auditado('propuesta_aceptada', $r));
    }

    public function test_respuesta_por_carta_exige_constancia_y_deja_el_caso_respondido(): void
    {
        $r = $this->nueva();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Respuesta formal.', 'canal' => 'carta'])
            ->assertUnprocessable()->assertJsonValidationErrors('constancia');
        $this->assertSame('abierto', $r->fresh()->estado);

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Respuesta formal.', 'canal' => 'carta', 'constancia' => '27/10/2026, Olva, guía 123'])
            ->assertOk();
        $r->refresh();
        $this->assertSame('respondido', $r->estado);
        $this->assertSame('Respuesta formal.', $r->respuesta);
        $this->assertTrue($this->auditado('respondida', $r));
    }

    public function test_respuesta_con_propuesta_pendiente_vuelve_con_error(): void
    {
        $r = $this->conPropuesta($this->nueva());

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'carta', 'constancia' => 'Cargo'])
            ->assertStatus(409)->assertJson(['message' => 'Resuelve primero la propuesta pendiente.']);
        $this->assertSame('abierto', $r->fresh()->estado);
    }

    // --- Correo real -------------------------------------------------------------------------------------------

    public function test_con_el_correo_activo_las_operaciones_que_envian_exigen_confirmacion(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->nueva();
        Queue::fake();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmar_envio');
        $this->gestionar($this->admin, $r, ['operacion' => 'propuesta', 'asunto' => 'A', 'cuerpo' => 'B'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmar_envio');
        Queue::assertNothingPushed();
        $this->assertSame(0, ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->whereIn('tipo', ['propuesta', 'respuesta'])->count());

        // La carta no envía correo: no exige confirmación aunque el correo esté activo.
        $this->gestionar($this->admin, $r, ['operacion' => 'nota', 'nota' => 'Sin confirmación'])->assertOk();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo', 'confirmar_envio' => '1'])
            ->assertOk();
        Queue::assertPushed(EnviarReclamacionCorreo::class);
        $this->assertSame('abierto', $r->fresh()->estado, 'Por correo el caso pasa a respondido solo cuando el correo se envía');
    }

    public function test_reintento_solo_desde_su_reclamacion_y_con_confirmacion(): void
    {
        $r = $this->conPropuesta($this->nueva());
        $otra = $this->nueva();
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->firstOrFail();
        $envio->forceFill(['estado' => 'fallido', 'intentos' => 4, 'error_codigo' => 'smtp'])->save();

        $this->gestionar($this->admin, $otra, ['operacion' => 'reintento', 'envio_id' => $envio->id])->assertNotFound();
        $this->assertSame('fallido', $envio->fresh()->estado);

        $this->habilitarReclamaciones(envio: true);
        Queue::fake();
        $this->gestionar($this->admin, $r, ['operacion' => 'reintento', 'envio_id' => $envio->id])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmar_envio');
        $this->assertSame('fallido', $envio->fresh()->estado);

        $this->gestionar($this->admin, $r, ['operacion' => 'reintento', 'envio_id' => $envio->id, 'confirmar_envio' => '1'])
            ->assertOk();
        $this->assertSame('pendiente', $envio->fresh()->estado);
        $this->assertSame(0, $envio->fresh()->intentos);
        Queue::assertPushed(EnviarReclamacionCorreo::class);
        $this->assertTrue($this->auditado('reintento', $r));
    }
}
