<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnviarReclamacionCorreo;
use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * E4 — Gestión de reclamaciones desde el panel (`POST admin/reclamaciones/{uuid}/gestion`), SQLite en memoria.
 * El servicio ya tiene sus pruebas de reglas (GestionReclamacionesTest); aquí se mide la capa HTTP: acceso,
 * despacho a la operación correcta, retorno con errores, confirmación del correo real y la sección del detalle.
 * No mide bloqueos ni concurrencia de PostgreSQL. No envía correos: la cola y el correo están simulados.
 */
class ReclamacionGestionPanelTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

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
    private function gestionar(User $usuario, Reclamacion $r, array $datos)
    {
        return $this->actingAs($usuario)
            ->from(route('admin.reclamaciones.show', $r))
            ->post(route('admin.reclamaciones.gestionar', $r), $datos);
    }

    private function auditado(string $accion, Reclamacion $r): bool
    {
        return AuditLog::query()->where('action', 'reclamacion_'.$accion)->where('model_id', $r->id)->exists();
    }

    private function conPropuesta(Reclamacion $r): Reclamacion
    {
        $this->gestionar($this->admin, $r, ['operacion' => 'propuesta', 'asunto' => 'Propuesta', 'cuerpo' => 'Texto de la propuesta.'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));

        return $r->fresh();
    }

    // --- Acceso ------------------------------------------------------------------------------------------------

    public function test_visitante_va_al_login_y_el_personal_sin_permiso_recibe_403(): void
    {
        $r = $this->nueva();
        $datos = ['operacion' => 'nota', 'nota' => 'Nota'];

        $this->post(route('admin.reclamaciones.gestionar', $r), $datos)->assertRedirect(route('login'));

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
            ->assertRedirect(route('admin.reclamaciones.show', $r));
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
        $this->actingAs($this->admin)->post('/admin/reclamaciones/no-es-uuid/gestion', ['operacion' => 'nota', 'nota' => 'x'])->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/reclamaciones/6f1b7f9e-2c1a-4b8e-9d1f-1a2b3c4d5e6f/gestion', ['operacion' => 'nota', 'nota' => 'x'])->assertNotFound();

        $r = $this->nueva();
        $this->actingAs($this->admin)->get(route('admin.reclamaciones.gestionar', $r))->assertStatus(405);
    }

    // --- Operaciones -------------------------------------------------------------------------------------------

    public function test_asignar_y_quitar_responsable(): void
    {
        $r = $this->nueva();
        $otro = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => $otro->id])
            ->assertRedirect(route('admin.reclamaciones.show', $r))
            ->assertSessionHas('success', 'Responsable actualizado.');
        $this->assertSame($otro->id, $r->fresh()->responsable_id);
        $this->assertTrue($this->auditado('responsable', $r));

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => ''])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
        $this->assertNull($r->fresh()->responsable_id);
    }

    public function test_no_se_asigna_a_quien_no_puede_ver_reclamaciones(): void
    {
        $r = $this->nueva();
        $sinPermiso = $this->usuarioConPermisos('secretaria');

        $this->gestionar($this->admin, $r, ['operacion' => 'responsable', 'responsable_id' => $sinPermiso->id])
            ->assertRedirect(route('admin.reclamaciones.show', $r))
            ->assertSessionHasErrors('responsable_id');
        $this->assertNull($r->fresh()->responsable_id);
    }

    public function test_reclasificar_y_rechazo_de_reclamo_sin_bien(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'tipo', 'tipo' => 'queja'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
        $this->assertSame('queja', $r->fresh()->tipo);
        $this->assertTrue($this->auditado('tipo', $r));

        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);
        $this->gestionar($this->admin, $queja, ['operacion' => 'tipo', 'tipo' => 'reclamo'])
            ->assertSessionHasErrors('tipo');
        $this->assertSame('queja', $queja->fresh()->tipo);
    }

    public function test_nota_queda_en_el_historial_y_se_muestra_escapada(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'nota', 'nota' => '<script>alert(1)</script> revisar'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));

        $this->assertDatabaseHas('reclamacion_historial', ['reclamacion_id' => $r->id, 'evento' => 'nota_interna']);
        $html = (string) $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $r))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; revisar', $html);
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
            ->assertRedirect(route('admin.reclamaciones.show', $queja))
            ->assertSessionHasErrors(['gestion' => 'Las propuestas de solución solo aplican a reclamos.'])
            ->assertSessionHasInput('operacion', 'propuesta');

        $this->assertNull($queja->fresh()->suspension_desde);
        // El alta pública ya deja la copia al consumidor; lo que no debe existir es un envío de propuesta.
        $this->assertSame(0, ReclamacionEnvio::query()->where('reclamacion_id', $queja->id)->where('tipo', 'propuesta')->count());
        $this->assertFalse($this->auditado('propuesta', $queja));
    }

    public function test_el_409_responde_json_cuando_se_pide(): void
    {
        $queja = $this->nueva(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.reclamaciones.gestionar', $queja), ['operacion' => 'propuesta', 'asunto' => 'A', 'cuerpo' => 'B'])
            ->assertStatus(409)
            ->assertJson(['message' => 'Las propuestas de solución solo aplican a reclamos.']);
    }

    public function test_rechazo_reanuda_el_plazo(): void
    {
        $r = $this->conPropuesta($this->nueva());
        $limiteAntes = $r->fecha_limite->toDateString();

        $this->gestionar($this->admin, $r, ['operacion' => 'rechazo', 'fecha_recepcion' => $r->suspension_desde->toDateString()])
            ->assertRedirect(route('admin.reclamaciones.show', $r));

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
            ->assertSessionHasErrors(['gestion' => 'La fecha de recepción está fuera de la ventana de la propuesta.']);
        $this->assertSame('abierto', $r->fresh()->estado);

        $this->gestionar($this->admin, $r, $datos + ['fecha_recepcion' => $r->suspension_desde->toDateString()])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
        $this->assertSame('concluido_por_acuerdo', $r->fresh()->estado);
        $this->assertTrue($this->auditado('propuesta_aceptada', $r));
    }

    public function test_respuesta_por_carta_exige_constancia_y_deja_el_caso_respondido(): void
    {
        $r = $this->nueva();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Respuesta formal.', 'canal' => 'carta'])
            ->assertSessionHasErrors('constancia');
        $this->assertSame('abierto', $r->fresh()->estado);

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Respuesta formal.', 'canal' => 'carta', 'constancia' => '27/10/2026, Olva, guía 123'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
        $r->refresh();
        $this->assertSame('respondido', $r->estado);
        $this->assertSame('Respuesta formal.', $r->respuesta);
        $this->assertTrue($this->auditado('respondida', $r));
    }

    public function test_respuesta_con_propuesta_pendiente_vuelve_con_error(): void
    {
        $r = $this->conPropuesta($this->nueva());

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'T', 'canal' => 'carta', 'constancia' => 'Cargo'])
            ->assertSessionHasErrors(['gestion' => 'Resuelve primero la propuesta pendiente.']);
        $this->assertSame('abierto', $r->fresh()->estado);
    }

    // --- Correo real -------------------------------------------------------------------------------------------

    public function test_con_el_correo_activo_las_operaciones_que_envian_exigen_confirmacion(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->nueva();
        Queue::fake();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo'])
            ->assertSessionHasErrors('confirmar_envio');
        $this->gestionar($this->admin, $r, ['operacion' => 'propuesta', 'asunto' => 'A', 'cuerpo' => 'B'])
            ->assertSessionHasErrors('confirmar_envio');
        Queue::assertNothingPushed();
        $this->assertSame(0, ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->whereIn('tipo', ['propuesta', 'respuesta'])->count());

        // La carta no envía correo: no exige confirmación aunque el correo esté activo.
        $this->gestionar($this->admin, $r, ['operacion' => 'nota', 'nota' => 'Sin confirmación'])->assertSessionHasNoErrors();

        $this->gestionar($this->admin, $r, ['operacion' => 'respuesta', 'respuesta' => 'Texto', 'canal' => 'correo', 'confirmar_envio' => '1'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
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
            ->assertSessionHasErrors('confirmar_envio');
        $this->assertSame('fallido', $envio->fresh()->estado);

        $this->gestionar($this->admin, $r, ['operacion' => 'reintento', 'envio_id' => $envio->id, 'confirmar_envio' => '1'])
            ->assertRedirect(route('admin.reclamaciones.show', $r));
        $this->assertSame('pendiente', $envio->fresh()->estado);
        $this->assertSame(0, $envio->fresh()->intentos);
        Queue::assertPushed(EnviarReclamacionCorreo::class);
        $this->assertTrue($this->auditado('reintento', $r));
    }

    // --- Vista -------------------------------------------------------------------------------------------------

    public function test_el_detalle_muestra_la_gestion_segun_el_estado(): void
    {
        $r = $this->nueva();
        $html = (string) $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $r))->assertOk()->getContent();

        $this->assertStringContainsString('H. Gestión del caso', $html);
        foreach (['gestion-responsable', 'gestion-tipo', 'gestion-nota', 'gestion-propuesta', 'gestion-respuesta'] as $modal) {
            $this->assertStringContainsString('id="'.$modal.'"', $html, $modal);
        }
        foreach (['gestion-rechazo', 'gestion-aceptacion'] as $modal) {
            $this->assertStringNotContainsString('id="'.$modal.'"', $html, "{$modal} solo con propuesta pendiente");
        }
        $this->assertStringContainsString('data-aviso-correo-inactivo', $html);
        $this->assertStringNotContainsString('data-aviso-correo-real', $html);

        $this->conPropuesta($r);
        $html = (string) $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $r))->getContent();
        foreach (['gestion-rechazo', 'gestion-aceptacion'] as $modal) {
            $this->assertStringContainsString('id="'.$modal.'"', $html, $modal);
        }
        foreach (['gestion-propuesta', 'gestion-respuesta'] as $modal) {
            $this->assertStringNotContainsString('id="'.$modal.'"', $html, "{$modal} no con propuesta pendiente");
        }
    }

    public function test_con_el_correo_activo_se_avisa_del_envio_real(): void
    {
        $this->habilitarReclamaciones(envio: true);
        $r = $this->nueva();

        $html = (string) $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $r))->getContent();
        $this->assertStringContainsString('data-aviso-correo-real', $html);
        $this->assertStringContainsString('Se enviará un correo real', $html);
        $this->assertStringContainsString('name="confirmar_envio"', $html);
    }

    public function test_quien_solo_ve_no_recibe_la_seccion_de_gestion(): void
    {
        $lector = $this->usuarioConPermisos('coordinador', ['reclamaciones.ver']);
        $r = $this->nueva();

        $html = (string) $this->actingAs($lector)->get(route('admin.reclamaciones.show', $r))->assertOk()->getContent();
        $this->assertStringNotContainsString('H. Gestión del caso', $html);
        $this->assertStringNotContainsString(route('admin.reclamaciones.gestionar', $r), $html);
    }

    public function test_un_error_reabre_el_modal_de_su_operacion(): void
    {
        $r = $this->nueva();
        $this->gestionar($this->admin, $r, ['operacion' => 'nota', 'nota' => ''])->assertSessionHasErrors('nota');

        $html = (string) $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $r))->getContent();
        $this->assertMatchesRegularExpression('#<dialog\s+id="gestion-nota"\s+data-admin-modal\s+data-auto-open#', $html);
        $this->assertDoesNotMatchRegularExpression('#<dialog\s+id="gestion-tipo"\s+data-admin-modal\s+data-auto-open#', $html);
    }
}
