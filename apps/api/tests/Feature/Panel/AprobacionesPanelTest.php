<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Aprobaciones internas en `/api/panel/v1/compartido/aprobaciones` (Ola 4, Fase 1): las reglas son las de
 * `GestionAprobaciones` (la API no decide ninguna), así que aquí se prueban sin atajos: autoridad por tipo, sin
 * autoaprobación, estado releído, motivo obligatorio, propietario o Gerencia para editar y eliminar, auditoría,
 * notificaciones y atomicidad. SQLite ignora `lockForUpdate()`: la serialización real se mide en
 * `tests/PostgresIntegrity/AprobacionesConcurrenciaPostgresTest.php`.
 */
final class AprobacionesPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/compartido/aprobaciones'.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function rol(string $rol): User
    {
        return $this->usuarioConRol($rol, $rol.'@horus.test');
    }

    private function solicitud(User $solicitante, string $tipo = AprobacionInterna::TIPO_COMPRA, string $estado = AprobacionInterna::ESTADO_PENDIENTE, array $extra = []): AprobacionInterna
    {
        return AprobacionInterna::create([
            'titulo' => 'Solicitud ficticia', 'tipo' => $tipo, 'estado' => $estado, 'solicitante_id' => $solicitante->id, ...$extra,
        ]);
    }

    // ---------------------------------------------------------------- acceso

    public function test_sin_sesion_es_401_y_un_usuario_sin_rol_no_entra(): void
    {
        $admin = $this->rol('admin');
        $s = $this->solicitud($admin);

        $this->como(null, 'GET')->assertUnauthorized();
        $comun = User::factory()->create();
        foreach ([['GET', ''], ['POST', "{$s->id}/aprobar"], ['DELETE', (string) $s->id]] as [$metodo, $ruta]) {
            $this->como($comun, $metodo, $ruta)->assertForbidden();
        }
        $this->assertSame('pendiente', $s->fresh()->estado);
    }

    // ---------------------------------------------------------------- autoridad

    public function test_gerencia_resuelve_compras_y_coordinacion_las_operativas(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $secretaria = $this->rol('secretaria');

        $compra = $this->solicitud($coordinador, AprobacionInterna::TIPO_COMPRA, extra: ['referencia' => 'REQ-1']);
        $this->como($admin, 'POST', "{$compra->id}/aprobar", ['comentario' => 'Conforme al presupuesto.'])
            ->assertOk()->assertJsonPath('message', 'La solicitud ha sido aprobada correctamente.')->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame($admin->id, $compra->fresh()->aprobador_id);
        $this->assertSame('Conforme al presupuesto.', $compra->fresh()->comentario);

        $operativa = $this->solicitud($secretaria, AprobacionInterna::TIPO_OPERATIVO);
        $this->como($coordinador, 'POST', "{$operativa->id}/aprobar")->assertOk();
        $this->assertSame($coordinador->id, $operativa->fresh()->aprobador_id);
    }

    public function test_nadie_resuelve_fuera_de_su_autoridad_ni_su_propia_solicitud(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $secretaria = $this->rol('secretaria');

        $operativa = $this->solicitud($secretaria, AprobacionInterna::TIPO_OPERATIVO);
        $compra = $this->solicitud($coordinador, AprobacionInterna::TIPO_COMPRA);
        $legado = $this->solicitud($secretaria, 'solicitud');
        $propia = $this->solicitud($admin, AprobacionInterna::TIPO_COMPRA);

        $casos = [
            'gerencia no resuelve lo operativo' => [$admin, $operativa],
            'coordinación no resuelve las compras' => [$coordinador, $compra],
            'secretaría no resuelve nada' => [$secretaria, $compra],
            'el tipo antiguo no tiene autoridad (gerencia)' => [$admin, $legado],
            'el tipo antiguo no tiene autoridad (coordinación)' => [$coordinador, $legado],
            'sin autoaprobación' => [$admin, $propia],
        ];
        foreach ($casos as $caso => [$usuario, $solicitud]) {
            $this->como($usuario, 'POST', "{$solicitud->id}/aprobar")->assertForbidden($caso);
            $this->como($usuario, 'POST', "{$solicitud->id}/rechazar", ['motivo' => 'Sin competencia'])->assertForbidden($caso);
        }
        foreach ([$operativa, $compra, $legado, $propia] as $solicitud) {
            $this->assertSame('pendiente', $solicitud->fresh()->estado);
        }
    }

    public function test_una_solicitud_ya_resuelta_no_se_decide_dos_veces(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $aprobada = $this->solicitud($coordinador, estado: 'aprobado', extra: ['aprobador_id' => $admin->id]);
        $rechazada = $this->solicitud($coordinador, estado: 'rechazado', extra: ['aprobador_id' => $admin->id, 'comentario' => 'Antes']);

        foreach ([$aprobada, $rechazada] as $solicitud) {
            $this->como($admin, 'POST', "{$solicitud->id}/aprobar")->assertStatus(409);
            $this->como($admin, 'POST', "{$solicitud->id}/rechazar", ['motivo' => 'Otro intento'])->assertStatus(409);
        }
        $this->assertSame('aprobado', $aprobada->fresh()->estado);
        $this->assertSame('Antes', $rechazada->fresh()->comentario);
    }

    // ---------------------------------------------------------------- motivo, reapertura y auditoría

    public function test_rechazar_y_reabrir_exigen_motivo_y_dejan_el_historial(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $s = $this->solicitud($coordinador);

        foreach (['', 'ab'] as $motivo) {
            $this->como($admin, 'POST', "{$s->id}/rechazar", ['motivo' => $motivo])->assertUnprocessable()->assertJsonValidationErrors(['motivo']);
        }
        $this->assertSame('pendiente', $s->fresh()->estado);
        $this->como($admin, 'POST', "{$s->id}/rechazar", ['motivo' => 'No hay presupuesto en la partida.'])
            ->assertOk()->assertJsonPath('data.estado', 'rechazado');
        $this->assertSame('No hay presupuesto en la partida.', $s->fresh()->comentario);

        $this->como($admin, 'POST', "{$s->id}/reabrir", ['motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors(['motivo']);
        $this->como($admin, 'POST', "{$s->id}/reabrir", ['motivo' => 'Llegaron nuevas cotizaciones.'])
            ->assertOk()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.aprobador', null);
        $this->como($admin, 'POST', "{$s->id}/reabrir", ['motivo' => 'Otra vez'])->assertStatus(409);

        $evento = AuditLog::query()->where('action', 'aprobacion_reabierta')->sole();
        $this->assertSame('rechazado', $evento->changes['estado_anterior']);
        $this->assertSame($admin->id, $evento->changes['aprobador_anterior_id']);
        $historial = $this->como($admin, 'GET')->json('data.0.historial');
        $this->assertSame(['aprobacion_reabierta', 'aprobacion_rechazada'], array_column(array_slice($historial, 0, 2), 'accion'));
    }

    public function test_las_decisiones_quedan_en_auditoria_y_avisan_a_quien_corresponde(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $s = $this->solicitud($coordinador, extra: ['referencia' => 'AUD-1']);

        $this->como($admin, 'POST', "{$s->id}/aprobar", ['comentario' => 'Aprobación auditada'])->assertOk();

        $log = AuditLog::query()->where('model_type', AprobacionInterna::class)->where('model_id', $s->id)->where('action', 'aprobacion_aprobada')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('pendiente', $log->changes['estado_anterior']);
        $this->assertSame('aprobado', $log->changes['estado_nuevo']);
        $this->assertSame($coordinador->id, $log->changes['solicitante_id']);
        $this->assertDatabaseHas('notificaciones_internas', [
            'user_id' => $coordinador->id, 'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL, 'titulo' => 'Cambio de aprobación',
        ]);
    }

    public function test_si_la_auditoria_falla_la_decision_no_deja_rastro(): void
    {
        $admin = $this->rol('admin');
        $s = $this->solicitud($this->rol('coordinador'));
        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo simulado de la auditoría');
        });

        $this->withoutExceptionHandling();
        try {
            $this->como($admin, 'POST', "{$s->id}/aprobar");
            $this->fail('La auditoría fallida debía abortar la operación.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame('pendiente', $s->fresh()->estado);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'aprobacion_aprobada']);
    }

    // ---------------------------------------------------------------- alta, edición y eliminación

    public function test_el_alta_fija_el_solicitante_y_el_estado_y_avisa_a_los_responsables(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');

        $this->como($coordinador, 'POST', '', [
            'titulo' => 'Compra de proyector', 'tipo' => 'compra', 'descripcion' => 'Para el aula 2.', 'referencia' => 'REQ-9',
            // Se ignoran: el solicitante es la sesión y el estado nace pendiente.
            'solicitante_id' => $admin->id, 'estado' => 'aprobado', 'aprobador_id' => $admin->id,
        ])->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.solicitante.id', $coordinador->id)
            ->assertJsonPath('data.puede_resolver', false)->assertJsonPath('data.es_propia', true);

        $s = AprobacionInterna::query()->sole();
        $this->assertNull($s->aprobador_id);
        $this->assertDatabaseHas('notificaciones_internas', ['user_id' => $admin->id, 'titulo' => 'Nueva solicitud de aprobación']);
        $this->assertDatabaseMissing('notificaciones_internas', ['user_id' => $coordinador->id, 'titulo' => 'Nueva solicitud de aprobación']);

        $this->como($coordinador, 'POST', '', ['titulo' => 'Mal tipo', 'tipo' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors(['tipo']);
        $this->como($coordinador, 'POST', '', ['tipo' => 'compra'])->assertUnprocessable()->assertJsonValidationErrors(['titulo']);
        // La secretaría solo ve y gestiona lo propio: no registra solicitudes.
        $this->como($this->rol('secretaria'), 'POST', '', ['titulo' => 'X', 'tipo' => 'compra'])->assertForbidden();
        $this->assertSame(1, AprobacionInterna::query()->count());
    }

    public function test_editar_no_cambia_estado_tipo_ni_solicitante(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $secretaria = $this->rol('secretaria');
        $s = $this->solicitud($coordinador);

        $base = ['titulo' => 'Editada', 'tipo' => AprobacionInterna::TIPO_COMPRA];
        $this->como($admin, 'PUT', (string) $s->id, [...$base, 'estado' => 'aprobado'])->assertUnprocessable()->assertJsonValidationErrors(['estado']);
        $this->como($admin, 'PUT', (string) $s->id, [...$base, 'solicitante_id' => $secretaria->id])->assertUnprocessable()->assertJsonValidationErrors(['solicitante_id']);
        $this->como($admin, 'PUT', (string) $s->id, [...$base, 'aprobador_id' => $admin->id])->assertUnprocessable()->assertJsonValidationErrors(['aprobador_id']);
        $this->como($admin, 'PUT', (string) $s->id, [...$base, 'tipo' => 'operativo'])->assertUnprocessable()->assertJsonValidationErrors(['tipo']);
        $this->assertSame('Solicitud ficticia', $s->fresh()->titulo);
        $this->assertSame($coordinador->id, $s->fresh()->solicitante_id);

        $this->como($admin, 'PUT', (string) $s->id, $base)->assertOk()->assertJsonPath('data.titulo', 'Editada');
        $this->assertDatabaseHas('audit_logs', ['action' => 'aprobacion_actualizada', 'model_id' => $s->id]);
    }

    public function test_editar_y_eliminar_son_del_solicitante_o_de_gerencia_y_solo_si_esta_pendiente(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $secretaria = $this->rol('secretaria');
        $datos = ['titulo' => 'Cambio', 'tipo' => AprobacionInterna::TIPO_OPERATIVO];

        // Cada solicitante, sobre lo suyo; Gerencia, sobre lo de otros; nadie más.
        foreach ([$coordinador, $secretaria, $admin] as $propietario) {
            $propia = $this->solicitud($propietario, AprobacionInterna::TIPO_OPERATIVO);
            $this->como($propietario, 'PUT', (string) $propia->id, $datos)->assertOk();
            $this->como($propietario, 'DELETE', (string) $propia->id)->assertOk();
            $this->assertDatabaseMissing('aprobaciones_internas', ['id' => $propia->id]);
        }
        $ajena = $this->solicitud($coordinador, AprobacionInterna::TIPO_OPERATIVO);
        $this->como($admin, 'PUT', (string) $ajena->id, $datos)->assertOk();
        foreach ([$secretaria] as $tercero) {
            $this->como($tercero, 'PUT', (string) $ajena->id, $datos)->assertForbidden();
            $this->como($tercero, 'DELETE', (string) $ajena->id)->assertForbidden();
        }
        $deGerencia = $this->solicitud($admin, AprobacionInterna::TIPO_COMPRA);
        $this->como($coordinador, 'PUT', (string) $deGerencia->id, [...$datos, 'tipo' => 'compra'])->assertForbidden();
        $this->como($coordinador, 'DELETE', (string) $deGerencia->id)->assertForbidden();
        $this->como($admin, 'DELETE', (string) $ajena->id)->assertOk();
        $this->assertDatabaseHas('aprobaciones_internas', ['id' => $deGerencia->id]);

        // Resuelta: ni se edita ni se elimina.
        $resuelta = $this->solicitud($coordinador, estado: 'aprobado', extra: ['aprobador_id' => $admin->id]);
        $this->como($admin, 'PUT', (string) $resuelta->id, [...$datos, 'tipo' => 'compra'])->assertStatus(409);
        $this->como($coordinador, 'DELETE', (string) $resuelta->id)->assertStatus(409);
        $this->assertSame('aprobado', $resuelta->fresh()->estado);
        $this->assertDatabaseHas('audit_logs', ['action' => 'aprobacion_eliminada']);
    }

    // ---------------------------------------------------------------- listado

    public function test_el_listado_pone_lo_pendiente_primero_y_ofrece_solo_las_acciones_permitidas(): void
    {
        $admin = $this->rol('admin');
        $coordinador = $this->rol('coordinador');
        $resuelta = $this->solicitud($coordinador, estado: 'rechazado', extra: ['titulo' => 'Antigua', 'aprobador_id' => $admin->id, 'comentario' => 'Rechazo']);
        $pendiente = $this->solicitud($coordinador, extra: ['titulo' => 'Nueva compra de proyector']);
        $propia = $this->solicitud($admin, extra: ['titulo' => 'Propia de gerencia']);

        $respuesta = $this->como($admin, 'GET')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertSame('rechazado', $respuesta->json('data.2.estado'));
        $porId = collect($respuesta->json('data'))->keyBy('id');
        $this->assertTrue($porId[$pendiente->id]['puede_resolver']);
        $this->assertFalse($porId[$pendiente->id]['es_propia']);
        $this->assertFalse($porId[$propia->id]['puede_resolver']);
        $this->assertTrue($porId[$propia->id]['puede_eliminar']);
        $this->assertTrue($porId[$resuelta->id]['puede_reabrir']);
        $this->assertFalse($porId[$resuelta->id]['puede_eliminar']);

        $this->como($coordinador, 'GET')->assertOk();
        $porIdCoord = collect($this->como($coordinador, 'GET')->json('data'))->keyBy('id');
        $this->assertFalse($porIdCoord[$pendiente->id]['puede_resolver'], 'Una compra no es de Coordinación, y además es suya.');
        $this->assertFalse($porIdCoord[$propia->id]['puede_resolver']);
        $this->assertFalse($porIdCoord[$propia->id]['puede_eliminar']);

        $this->como($admin, 'GET', '?estado=pendiente')->assertJsonPath('meta.total', 2);
        $this->como($admin, 'GET', '?estado=inexistente')->assertJsonPath('meta.total', 3);
        $this->como($admin, 'GET', '?tipo=operativo')->assertJsonPath('meta.total', 0);
        $this->como($admin, 'GET', '?busqueda=proyector')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.titulo', 'Nueva compra de proyector');
        // Mayúsculas y minúsculas no cuentan, y `%` se busca como texto, no como comodín.
        $this->como($admin, 'GET', '?busqueda=PROYECTOR')->assertJsonPath('meta.total', 1);
        $this->solicitud($coordinador, extra: ['titulo' => 'Descuento del 30% ficticio']);
        $this->como($admin, 'GET', '?busqueda=%25')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.titulo', 'Descuento del 30% ficticio');
    }
}
