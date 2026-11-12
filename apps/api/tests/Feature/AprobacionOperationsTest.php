<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AprobacionOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staffUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Escenario 1: Gerencia puede aprobar los tipos de solicitud que le corresponden (compra).
     */
    public function test_gerencia_can_approve_purchase_requests(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra de equipamiento',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
            'referencia' => 'REQ-2026-001',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud), [
                'comentario' => 'Aprobado conforme al presupuesto anual.',
            ]);

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $solicitud->refresh();

        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->estado);
        $this->assertSame($admin->id, $solicitud->aprobador_id);
        $this->assertSame('Aprobado conforme al presupuesto anual.', $solicitud->comentario);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_aprobada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $admin->id,
        ]);
    }

    /**
     * Escenario 2: Coordinación puede aprobar los tipos de solicitud que le corresponden (operativo).
     */
    public function test_coordinacion_can_approve_operational_requests(): void
    {
        $coordinador = $this->staffUser('coordinador');
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Ajuste de horarios de fin de semana',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
            'referencia' => 'HOR-2026-002',
        ]);

        $response = $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.aprobar', $solicitud), [
                'comentario' => 'Horario ajustado según disponibilidad docente.',
            ]);

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $solicitud->refresh();

        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->estado);
        $this->assertSame($coordinador->id, $solicitud->aprobador_id);
    }

    /**
     * Escenario 3: Gerencia no puede resolver tipos asignados exclusivamente a Coordinación (operativo).
     */
    public function test_gerencia_cannot_resolve_requests_assigned_exclusively_to_coordinacion(): void
    {
        $admin = $this->staffUser('admin');
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Cambio de turno de secretaría',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
        ]);

        // Intento de aprobar por Gerencia -> 403 Forbidden
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertForbidden();

        // Intento de rechazar por Gerencia -> 403 Forbidden
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => 'Rechazado sin competencia'])
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
    }

    /**
     * Escenario 4: Coordinación no puede resolver tipos asignados exclusivamente a Gerencia (compra).
     */
    public function test_coordinacion_cannot_resolve_requests_assigned_exclusively_to_gerencia(): void
    {
        $coordinador = $this->staffUser('coordinador');
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra de proyector',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
        ]);

        // Intento de aprobar por Coordinación -> 403 Forbidden
        $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertForbidden();

        // Intento de rechazar por Coordinación -> 403 Forbidden
        $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => 'Coordinación no autoriza presupuesto'])
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
    }

    /**
     * Escenario 5: Secretaría no puede aprobar ni rechazar solicitudes.
     */
    public function test_secretaria_cannot_approve_or_reject_requests(): void
    {
        $secretaria = $this->staffUser('secretaria');
        $coordinador = $this->staffUser('coordinador');

        $compra = AprobacionInterna::create([
            'titulo' => 'Compra de suministros',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $operativo = AprobacionInterna::create([
            'titulo' => 'Apertura de aula adicional',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $this->actingAs($secretaria)
            ->post(route('admin.aprobaciones.aprobar', $compra))
            ->assertForbidden();

        $this->actingAs($secretaria)
            ->post(route('admin.aprobaciones.rechazar', $compra), ['motivo' => 'Motivo'])
            ->assertForbidden();

        $this->actingAs($secretaria)
            ->post(route('admin.aprobaciones.aprobar', $operativo))
            ->assertForbidden();

        $this->actingAs($secretaria)
            ->post(route('admin.aprobaciones.rechazar', $operativo), ['motivo' => 'Motivo'])
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $compra->fresh()->estado);
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $operativo->fresh()->estado);
    }

    /**
     * Escenario 6: Un usuario común sin roles no puede resolver solicitudes.
     */
    public function test_common_user_without_roles_cannot_resolve_requests(): void
    {
        $comun = User::factory()->create();
        $admin = $this->staffUser('admin');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra general',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $admin->id,
        ]);

        $this->actingAs($comun)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertForbidden();

        $this->actingAs($comun)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => 'Sin rol'])
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
    }

    /**
     * Seguridad: El tipo legacy 'solicitud' no tiene autoridad asignada y nadie puede resolverlo arbitrariamente.
     */
    public function test_legacy_solicitud_type_cannot_be_resolved_arbitrarily_by_anyone(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');
        $secretaria = $this->staffUser('secretaria');

        $solicitudLegacy = AprobacionInterna::create([
            'titulo' => 'Solicitud antigua sin categorizar',
            'tipo' => 'solicitud',
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
        ]);

        // Gerencia no puede resolver tipo 'solicitud'
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitudLegacy))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitudLegacy), ['motivo' => 'Motivo de rechazo'])
            ->assertForbidden();

        // Coordinación no puede resolver tipo 'solicitud'
        $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.aprobar', $solicitudLegacy))
            ->assertForbidden();

        $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.rechazar', $solicitudLegacy), ['motivo' => 'Motivo de rechazo'])
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitudLegacy->fresh()->estado);
    }

    /**
     * Escenario 7: Prohibición de autoaprobación (el solicitante no puede aprobar ni rechazar su solicitud).
     */
    public function test_solicitante_cannot_approve_or_reject_their_own_request(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        // Admin crea solicitud de compra
        $solicitudCompra = AprobacionInterna::create([
            'titulo' => 'Mi propia compra gerencial',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $admin->id,
        ]);

        // Intento de autoaprobarse -> 403 Forbidden
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitudCompra))
            ->assertForbidden();

        // Intento de autorechazarse -> 403 Forbidden
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitudCompra), ['motivo' => 'Me rechazo'])
            ->assertForbidden();

        // Coordinador crea solicitud operativa
        $solicitudOperativa = AprobacionInterna::create([
            'titulo' => 'Mi propio cambio de horario',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        // Intento de autoaprobarse -> 403 Forbidden
        $this->actingAs($coordinador)
            ->post(route('admin.aprobaciones.aprobar', $solicitudOperativa))
            ->assertForbidden();

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitudCompra->fresh()->estado);
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitudOperativa->fresh()->estado);
    }

    /**
     * Escenario 8: No se puede decidir dos veces una solicitud ya resuelta.
     */
    public function test_cannot_resolve_already_resolved_request_twice(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra de libros',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_APROBADO,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
        ]);

        // Reintentar aprobar una solicitud ya aprobada -> 409 Conflict
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertStatus(409);

        // Intentar rechazar una solicitud ya aprobada -> 409 Conflict
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => 'Rechazar lo aprobado'])
            ->assertStatus(409);

        $solicitudRechazada = AprobacionInterna::create([
            'titulo' => 'Compra de software',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_RECHAZADO,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
            'comentario' => 'Rechazo previo',
        ]);

        // Reintentar aprobar una solicitud ya rechazada -> 409 Conflict
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitudRechazada))
            ->assertStatus(409);

        // Reintentar rechazar una solicitud ya rechazada -> 409 Conflict
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitudRechazada), ['motivo' => 'Otro rechazo'])
            ->assertStatus(409);
    }

    /**
     * Escenario 9: No se puede modificar el estado mediante el CRUD genérico (update).
     */
    public function test_cannot_modify_status_through_generic_crud(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud pendiente',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $response = $this->actingAs($admin)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Solicitud editada',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
                'estado' => AprobacionInterna::ESTADO_APROBADO,
            ]);

        $response->assertSessionHasErrors('estado');
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
    }

    /**
     * Escenario 10: No se puede sustituir arbitrariamente al solicitante ni al responsable.
     */
    public function test_cannot_substitute_applicant_arbitrarily(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud con solicitante original',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        // Intento de suplantar solicitante en update -> Error de validación
        $response = $this->actingAs($admin)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Solicitud con solicitante suplantado',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
                'solicitante_id' => $secretaria->id,
            ]);

        $response->assertSessionHasErrors('solicitante_id');
        $this->assertSame($coordinador->id, $solicitud->fresh()->solicitante_id);
    }

    /**
     * Escenario 11: Los rechazos requieren motivo obligatorio.
     */
    public function test_rejection_requires_mandatory_motivo(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra sin fondos',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        // Rechazo sin motivo -> Error 422
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);

        // Rechazo con motivo válido -> Éxito
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), [
                'motivo' => 'No hay presupuesto asignado en la partida actual.',
            ])
            ->assertRedirect(route('admin.aprobaciones.index'));

        $solicitud->refresh();
        $this->assertSame(AprobacionInterna::ESTADO_RECHAZADO, $solicitud->estado);
        $this->assertSame('No hay presupuesto asignado en la partida actual.', $solicitud->comentario);
    }

    /**
     * Escenario 12: Las reaperturas exigen motivo y conservan el historial anterior.
     */
    public function test_reopening_requires_motivo_and_preserves_history(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra inicialmente rechazada',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_RECHAZADO,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
            'comentario' => 'Rechazada por falta de cotizaciones.',
        ]);

        // Reapertura sin motivo -> Falla validación
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.reabrir', $solicitud), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(AprobacionInterna::ESTADO_RECHAZADO, $solicitud->fresh()->estado);

        // Reapertura con motivo válido por Gerencia
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.reabrir', $solicitud), [
                'motivo' => 'Se adjuntaron nuevas cotizaciones que ameritan reconsideración.',
            ])
            ->assertRedirect(route('admin.aprobaciones.index'));

        $solicitud->refresh();
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->estado);

        // Comprobar que en auditoría se conservó el evento de reapertura con el estado anterior
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_reabierta',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $admin->id,
        ]);
    }

    /**
     * Escenario 13: Las decisiones quedan registradas en auditoría con trazabilidad completa.
     */
    public function test_decisions_are_recorded_in_audit_log(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Auditoría integral',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
            'referencia' => 'AUD-001',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud), ['comentario' => 'Aprobación auditada']);

        $log = AuditLog::where('model_type', AprobacionInterna::class)
            ->where('model_id', $solicitud->id)
            ->where('action', 'aprobacion_aprobada')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $log->changes['estado_anterior']);
        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $log->changes['estado_nuevo']);
        $this->assertSame($coordinador->id, $log->changes['solicitante_id']);
        $this->assertSame($admin->id, $log->changes['responsable_id']);
    }

    /**
     * Escenario 14: Las notificaciones llegan a los destinatarios correctos.
     */
    public function test_notifications_reach_correct_recipients_for_all_transitions(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        // 1. Al crear con aprobador explícito, se notifica al aprobador
        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud notificada',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('notificaciones_internas', [
            'user_id' => $admin->id,
            'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
            'titulo' => 'Nueva solicitud de aprobación',
        ]);

        // 2. Al aprobar, se notifica al solicitante
        $this->actingAs($admin)->post(route('admin.aprobaciones.aprobar', $solicitud));

        $this->assertDatabaseHas('notificaciones_internas', [
            'user_id' => $coordinador->id,
            'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
            'titulo' => 'Cambio de aprobación',
        ]);
    }

    /**
     * Escenario 15: Las operaciones fallidas no dejan registros parciales (atomicidad y rollback).
     */
    public function test_failed_operations_leave_no_partial_records(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Operación atómica',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        // Simular fallo en la escritura de auditoría
        AuditLog::creating(function (): void {
            throw new RuntimeException('Error de base de datos simulado');
        });

        try {
            $this->actingAs($admin)->post(route('admin.aprobaciones.aprobar', $solicitud));
        } catch (RuntimeException) {
            // Esperado
        }

        // El estado debe permanecer en pendiente
        $this->assertSame(AprobacionInterna::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'aprobacion_aprobada']);
    }

    /**
     * Escenario 16: Concurrencia controlada: una solicitud ya resuelta no permite sobrescritura.
     */
    public function test_concurrency_locks_row_and_prevents_simultaneous_overwrites(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra concurrente',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        // Primer decisor aprueba válidamente
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertRedirect(route('admin.aprobaciones.index'));

        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->fresh()->estado);

        // Segundo proceso simultáneo intenta rechazar -> rechaza con 409 y no sobrescribe
        $this->actingAs($admin)
            ->post(route('admin.aprobaciones.rechazar', $solicitud), ['motivo' => 'Decisión retrasada'])
            ->assertStatus(409);

        // El estado sigue siendo 'aprobado'
        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->fresh()->estado);
    }

    /**
     * Política ratificada Opción A: El solicitante original puede modificar su propia solicitud pendiente.
     */
    public function test_solicitante_can_update_their_own_pending_request(): void
    {
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud inicial del coordinador',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
            'descripcion' => 'Descripción original',
        ]);

        $response = $this->actingAs($coordinador)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Solicitud editada por el solicitante',
                'tipo' => AprobacionInterna::TIPO_OPERATIVO,
                'descripcion' => 'Descripción actualizada por el propio solicitante',
            ]);

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $solicitud->refresh();

        $this->assertSame('Solicitud editada por el solicitante', $solicitud->titulo);
        $this->assertSame('Descripción actualizada por el propio solicitante', $solicitud->descripcion);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_actualizada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $coordinador->id,
        ]);
    }

    /**
     * Política ratificada Opción A: El solicitante original puede eliminar su propia solicitud pendiente.
     */
    public function test_solicitante_can_delete_their_own_pending_request(): void
    {
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud a descartar por solicitante',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $response = $this->actingAs($coordinador)
            ->delete(route('admin.aprobaciones.destroy', $solicitud));

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $this->assertDatabaseMissing('aprobaciones_internas', ['id' => $solicitud->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_eliminada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $coordinador->id,
        ]);
    }

    /**
     * Política ratificada Opción A: Gerencia (admin) puede modificar solicitudes pendientes de terceros.
     */
    public function test_gerencia_can_update_third_party_pending_request(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud creada por coordinación',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
            'descripcion' => 'Detalle original',
        ]);

        $response = $this->actingAs($admin)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Título ajustado por Gerencia',
                'tipo' => AprobacionInterna::TIPO_OPERATIVO,
                'descripcion' => 'Gerencia precisa el alcance de la solicitud',
            ]);

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $solicitud->refresh();

        $this->assertSame('Título ajustado por Gerencia', $solicitud->titulo);
        $this->assertSame('Gerencia precisa el alcance de la solicitud', $solicitud->descripcion);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_actualizada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $admin->id,
        ]);
    }

    /**
     * Política ratificada Opción A: Gerencia (admin) puede eliminar solicitudes pendientes de terceros.
     */
    public function test_gerencia_can_delete_third_party_pending_request(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud pendiente a depurar',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $response = $this->actingAs($admin)
            ->delete(route('admin.aprobaciones.destroy', $solicitud));

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $this->assertDatabaseMissing('aprobaciones_internas', ['id' => $solicitud->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_eliminada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $admin->id,
        ]);
    }

    /**
     * Política ratificada Opción A: Coordinación no puede modificar solicitudes operativas pendientes de terceros.
     */
    public function test_coordinacion_cannot_update_third_party_pending_request(): void
    {
        $coordinadorActor = $this->staffUser('coordinador');
        $otroCoordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud operativa de otro coordinador',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $otroCoordinador->id,
            'descripcion' => 'Descripción intacta',
        ]);

        $response = $this->actingAs($coordinadorActor)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Intento de edición no autorizada',
                'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            ]);

        $response->assertForbidden();
        $this->assertSame('Solicitud operativa de otro coordinador', $solicitud->fresh()->titulo);
    }

    /**
     * Política ratificada Opción A: Coordinación no puede eliminar solicitudes operativas pendientes de terceros.
     */
    public function test_coordinacion_cannot_delete_third_party_pending_request(): void
    {
        $coordinadorActor = $this->staffUser('coordinador');
        $otroCoordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud operativa no eliminable por terceros',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $otroCoordinador->id,
        ]);

        $response = $this->actingAs($coordinadorActor)
            ->delete(route('admin.aprobaciones.destroy', $solicitud));

        $response->assertForbidden();
        $this->assertDatabaseHas('aprobaciones_internas', ['id' => $solicitud->id]);
    }

    /**
     * Política ratificada Opción A: Secretaría, siendo la solicitante original, puede editar su propia solicitud pendiente.
     */
    public function test_secretaria_as_original_applicant_can_update_their_own_pending_request(): void
    {
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud inicial de secretaría',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
            'descripcion' => 'Descripción redactada por secretaría',
        ]);

        $response = $this->actingAs($secretaria)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Solicitud editada por la secretaria solicitante',
                'tipo' => AprobacionInterna::TIPO_OPERATIVO,
                'descripcion' => 'Descripción actualizada por la propia secretaria',
            ]);

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $solicitud->refresh();

        $this->assertSame('Solicitud editada por la secretaria solicitante', $solicitud->titulo);
        $this->assertSame('Descripción actualizada por la propia secretaria', $solicitud->descripcion);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_actualizada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $secretaria->id,
        ]);
    }

    /**
     * Política ratificada Opción A: Secretaría, siendo la solicitante original, puede eliminar su propia solicitud pendiente.
     */
    public function test_secretaria_as_original_applicant_can_delete_their_own_pending_request(): void
    {
        $secretaria = $this->staffUser('secretaria');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud a descartar por secretaría',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $secretaria->id,
        ]);

        $response = $this->actingAs($secretaria)
            ->delete(route('admin.aprobaciones.destroy', $solicitud));

        $response->assertRedirect(route('admin.aprobaciones.index'));
        $this->assertDatabaseMissing('aprobaciones_internas', ['id' => $solicitud->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'aprobacion_eliminada',
            'model_type' => AprobacionInterna::class,
            'model_id' => $solicitud->id,
            'user_id' => $secretaria->id,
        ]);
    }

    /**
     * Política ratificada Opción A: Secretaría no puede modificar ni eliminar solicitudes pendientes de terceros.
     */
    public function test_secretaria_cannot_update_or_delete_third_party_pending_request(): void
    {
        $secretaria = $this->staffUser('secretaria');
        $coordinador = $this->staffUser('coordinador');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud de coordinación',
            'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $coordinador->id,
        ]);

        $this->actingAs($secretaria)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Alteración por secretaría',
                'tipo' => AprobacionInterna::TIPO_OPERATIVO,
            ])
            ->assertForbidden();

        $this->actingAs($secretaria)
            ->delete(route('admin.aprobaciones.destroy', $solicitud))
            ->assertForbidden();

        $this->assertDatabaseHas('aprobaciones_internas', ['id' => $solicitud->id]);
    }

    /**
     * Política ratificada Opción A: Secretaría y usuarios comunes no pueden modificar ni eliminar solicitudes.
     */
    public function test_secretaria_and_common_user_cannot_bypass_restrictions_to_update_or_delete(): void
    {
        $secretaria = $this->staffUser('secretaria');
        $comun = User::factory()->create();
        $admin = $this->staffUser('admin');

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud protegida contra roles sin gestionar',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $admin->id,
        ]);

        // Secretaría no tiene permiso 'aprobaciones.gestionar' -> 403 Forbidden
        $this->actingAs($secretaria)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Hack por secretaria',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
            ])
            ->assertForbidden();

        $this->actingAs($secretaria)
            ->delete(route('admin.aprobaciones.destroy', $solicitud))
            ->assertForbidden();

        // Usuario común sin rol administrativo -> 403 Forbidden
        $this->actingAs($comun)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Hack por común',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
            ])
            ->assertForbidden();

        $this->actingAs($comun)
            ->delete(route('admin.aprobaciones.destroy', $solicitud))
            ->assertForbidden();

        $this->assertDatabaseHas('aprobaciones_internas', ['id' => $solicitud->id]);
    }

    /**
     * Seguridad: Un usuario común sin rol de staff no puede actualizar ni eliminar
     * solicitudes aunque figure artificialmente como solicitante_id, debido a la
     * barrera del middleware de rol y permisos del panel administrativo.
     */
    public function test_common_user_cannot_update_or_delete_even_if_applicant(): void
    {
        $comun = User::factory()->create();

        $solicitud = AprobacionInterna::create([
            'titulo' => 'Solicitud con solicitante común',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $comun->id,
        ]);

        // Petición directa PUT debe ser rechazada con 403 Forbidden
        $this->actingAs($comun)
            ->put(route('admin.aprobaciones.update', $solicitud), [
                'titulo' => 'Intento de modificar por usuario común',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
            ])
            ->assertForbidden();

        // Petición directa DELETE debe ser rechazada con 403 Forbidden
        $this->actingAs($comun)
            ->delete(route('admin.aprobaciones.destroy', $solicitud))
            ->assertForbidden();

        // El registro permanece intacto
        $this->assertDatabaseHas('aprobaciones_internas', [
            'id' => $solicitud->id,
            'titulo' => 'Solicitud con solicitante común',
        ]);
    }

    /**
     * Política ratificada Opción A: Una solicitud resuelta no puede modificarse ni eliminarse.
     */
    public function test_resolved_request_cannot_be_updated_or_deleted(): void
    {
        $admin = $this->staffUser('admin');
        $coordinador = $this->staffUser('coordinador');

        // Solicitud aprobada
        $solicitudAprobada = AprobacionInterna::create([
            'titulo' => 'Solicitud ya aprobada',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_APROBADO,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
        ]);

        // Solicitud rechazada
        $solicitudRechazada = AprobacionInterna::create([
            'titulo' => 'Solicitud ya rechazada',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_RECHAZADO,
            'solicitante_id' => $coordinador->id,
            'aprobador_id' => $admin->id,
            'comentario' => 'Rechazada previamente',
        ]);

        // Intento de modificar solicitud aprobada por Gerencia -> 409 Conflict
        $this->actingAs($admin)
            ->put(route('admin.aprobaciones.update', $solicitudAprobada), [
                'titulo' => 'Intento de modificar aprobada',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
            ])
            ->assertStatus(409);

        // Intento de eliminar solicitud aprobada por Gerencia -> 409 Conflict
        $this->actingAs($admin)
            ->delete(route('admin.aprobaciones.destroy', $solicitudAprobada))
            ->assertStatus(409);

        // Intento de modificar solicitud rechazada por el solicitante -> 409 Conflict
        $this->actingAs($coordinador)
            ->put(route('admin.aprobaciones.update', $solicitudRechazada), [
                'titulo' => 'Intento de modificar rechazada',
                'tipo' => AprobacionInterna::TIPO_COMPRA,
            ])
            ->assertStatus(409);

        // Intento de eliminar solicitud rechazada por el solicitante -> 409 Conflict
        $this->actingAs($coordinador)
            ->delete(route('admin.aprobaciones.destroy', $solicitudRechazada))
            ->assertStatus(409);

        // Los registros permanecen intactos
        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitudAprobada->fresh()->estado);
        $this->assertSame(AprobacionInterna::ESTADO_RECHAZADO, $solicitudRechazada->fresh()->estado);
    }
}
