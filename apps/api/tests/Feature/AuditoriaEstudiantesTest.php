<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Estudiante;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 20, Fase 2a: auditoría de estudiantes (crear, actualizar, eliminar lógicamente, restaurar).
 * SQLite en memoria: no mide bloqueos ni concurrencia de PostgreSQL.
 */
class AuditoriaEstudiantesTest extends TestCase
{
    use RefreshDatabase;

    private const ACCIONES = ['estudiante_creado', 'estudiante_actualizado', 'estudiante_eliminado', 'estudiante_restaurado'];

    /** Marcadores únicos: no deben aparecer jamás en audit_logs.changes. */
    private const DNI = '81726354';

    private const NOMBRES = 'NOMBRE-MARCA-7731';

    private const APELLIDOS = 'APELLIDO-MARCA-7731';

    private const EMAIL = 'marca7731@example.test';

    private const TELEFONO = 'TEL-MARCA-7731';

    private const DIRECCION = 'DIRECCION-MARCA-7731';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function usuario(string $rol): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    /** @return array<string, string> */
    private function datos(array $extra = []): array
    {
        return array_replace([
            'dni' => self::DNI,
            'nombres' => self::NOMBRES,
            'apellidos' => self::APELLIDOS,
            'email' => self::EMAIL,
            'telefono' => self::TELEFONO,
            'fecha_nacimiento' => '2000-04-15',
            'direccion' => self::DIRECCION,
            'estado' => 'activo',
        ], $extra);
    }

    private function estudiante(array $extra = []): Estudiante
    {
        return Estudiante::create($this->datos($extra));
    }

    /** @return Collection<int, AuditLog> */
    private function auditorias(): Collection
    {
        return AuditLog::query()->whereIn('action', self::ACCIONES)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function cambios(AuditLog $log): array
    {
        $json = DB::table('audit_logs')->where('id', $log->id)->value('changes');
        $this->assertIsString($json);
        $decodificado = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodificado);

        return $decodificado;
    }

    private function assertSinMarcadores(AuditLog $log): void
    {
        $crudo = (string) DB::table('audit_logs')->where('id', $log->id)->value('changes');
        foreach ([self::DNI, self::NOMBRES, self::APELLIDOS, self::EMAIL, self::TELEFONO, self::DIRECCION, '2000-04-15', 'MARCA-7731'] as $marcador) {
            $this->assertStringNotContainsString($marcador, $crudo, "El marcador {$marcador} apareció en changes.");
        }
    }

    private function failAudit(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
    }

    // --- Crear ---------------------------------------------------------------

    public function test_crear_registra_una_auditoria_con_actor_modelo_y_sin_datos_personales(): void
    {
        $admin = $this->usuario('admin');
        $otro = $this->usuario('admin');

        $this->actingAs($admin)
            ->post(route('admin.estudiantes.store'), $this->datos())
            ->assertRedirect(route('admin.estudiantes.index'))
            ->assertSessionHas('success', 'El estudiante se registró correctamente.');

        $estudiante = Estudiante::where('dni', self::DNI)->firstOrFail();
        $logs = $this->auditorias();
        $this->assertCount(1, $logs);
        $log = $logs->first();

        $this->assertSame('estudiante_creado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertNotSame($otro->id, $log->user_id);
        $this->assertSame(Estudiante::class, $log->model_type);
        $this->assertSame($estudiante->id, (int) $log->model_id);
        $this->assertNotNull($log->created_at);

        $cambios = $this->cambios($log);
        $this->assertSame(1, $cambios['esquema']);
        $this->assertSame(['estado' => 'activo'], $cambios['valores']);
        $this->assertEqualsCanonicalizing(
            ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion', 'estado'],
            $cambios['campos'],
        );
        $this->assertSinMarcadores($log);
    }

    public function test_crear_con_validacion_fallida_no_audita_ni_crea(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->post(route('admin.estudiantes.store'), $this->datos(['dni' => '123']))
            ->assertSessionHasErrors(['dni']);

        $this->assertSame(0, Estudiante::count());
        $this->assertCount(0, $this->auditorias());
    }

    public function test_crear_revierte_el_alta_si_falla_la_auditoria(): void
    {
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->postJson(route('admin.estudiantes.store'), $this->datos())->assertStatus(500);

        $this->assertDatabaseMissing('estudiantes', ['dni' => self::DNI]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Actualizar ----------------------------------------------------------

    public function test_actualizar_registra_estado_con_valores_y_campos_sensibles_solo_por_nombre(): void
    {
        $admin = $this->usuario('admin');
        $estudiante = $this->estudiante();

        $this->actingAs($admin)
            ->put(route('admin.estudiantes.update', $estudiante), $this->datos([
                'dni' => '81726355',
                'nombres' => 'OTRO-NOMBRE-MARCA-7731',
                'email' => 'otra7731@example.test',
                'estado' => 'inactivo',
            ]))
            ->assertRedirect(route('admin.estudiantes.show', $estudiante))
            ->assertSessionHas('success', 'Los datos del estudiante se actualizaron correctamente.');

        $logs = $this->auditorias();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('estudiante_actualizado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Estudiante::class, $log->model_type);
        $this->assertSame($estudiante->id, (int) $log->model_id);

        $cambios = $this->cambios($log);
        $this->assertSame(1, $cambios['esquema']);
        $this->assertEqualsCanonicalizing(['dni', 'nombres', 'email', 'estado'], $cambios['campos']);
        $this->assertSame(['estado' => 'activo'], $cambios['antes']);
        $this->assertSame(['estado' => 'inactivo'], $cambios['despues']);
        $this->assertSinMarcadores($log);
        $this->assertStringNotContainsString('81726355', (string) DB::table('audit_logs')->value('changes'));
        $this->assertStringNotContainsString('otra7731', (string) DB::table('audit_logs')->value('changes'));

        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'dni' => '81726355', 'estado' => 'inactivo']);
    }

    public function test_actualizar_solo_un_campo_sensible_no_guarda_ningun_valor(): void
    {
        $estudiante = $this->estudiante();

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.estudiantes.update', $estudiante), $this->datos(['direccion' => 'NUEVA-DIRECCION-MARCA-7731']))
            ->assertRedirect(route('admin.estudiantes.show', $estudiante));

        $log = $this->auditorias()->sole();
        $cambios = $this->cambios($log);
        $this->assertSame(['direccion'], $cambios['campos']);
        $this->assertSame([], $cambios['antes']);
        $this->assertSame([], $cambios['despues']);
        $this->assertSinMarcadores($log);
        $this->assertStringNotContainsString('NUEVA-DIRECCION', (string) DB::table('audit_logs')->value('changes'));
    }

    public function test_actualizar_sin_diferencias_reales_no_audita(): void
    {
        $estudiante = $this->estudiante();
        $actualizado = $estudiante->fresh()->updated_at;

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.estudiantes.update', $estudiante), $this->datos())
            ->assertRedirect(route('admin.estudiantes.show', $estudiante))
            ->assertSessionHas('success');

        $this->assertCount(0, $this->auditorias());
        $this->assertEquals($actualizado, $estudiante->fresh()->updated_at);
    }

    public function test_actualizar_con_validacion_fallida_no_audita_ni_modifica(): void
    {
        $estudiante = $this->estudiante();

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.estudiantes.update', $estudiante), $this->datos(['estado' => 'invalido', 'nombres' => 'CAMBIO-MARCA-7731']))
            ->assertSessionHasErrors(['estado']);

        $this->assertCount(0, $this->auditorias());
        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'nombres' => self::NOMBRES, 'estado' => 'activo']);
    }

    public function test_actualizar_revierte_los_cambios_si_falla_la_auditoria(): void
    {
        $estudiante = $this->estudiante();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->putJson(route('admin.estudiantes.update', $estudiante), $this->datos(['estado' => 'inactivo']))->assertStatus(500);

        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'estado' => 'activo']);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Eliminar ------------------------------------------------------------

    public function test_eliminar_registra_una_operacion_minima_sin_datos_personales(): void
    {
        $admin = $this->usuario('admin');
        $estudiante = $this->estudiante();

        $this->actingAs($admin)
            ->delete(route('admin.estudiantes.destroy', $estudiante))
            ->assertRedirect(route('admin.estudiantes.index'))
            ->assertSessionHas('success', 'El estudiante se desactivó correctamente.');

        $this->assertSoftDeleted('estudiantes', ['id' => $estudiante->id]);
        $log = $this->auditorias()->sole();
        $this->assertSame('estudiante_eliminado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Estudiante::class, $log->model_type);
        $this->assertSame($estudiante->id, (int) $log->model_id);
        $this->assertSame(['esquema' => 1, 'operacion' => 'eliminacion_logica'], $this->cambios($log));
        $this->assertSinMarcadores($log);
    }

    public function test_eliminar_dos_veces_no_duplica_la_auditoria(): void
    {
        $estudiante = $this->estudiante();
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->delete(route('admin.estudiantes.destroy', $estudiante))->assertRedirect();
        $this->delete(route('admin.estudiantes.destroy', $estudiante->id))->assertNotFound();

        $this->assertCount(1, $this->auditorias());
    }

    public function test_eliminar_revierte_si_falla_la_auditoria(): void
    {
        $estudiante = $this->estudiante();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->deleteJson(route('admin.estudiantes.destroy', $estudiante))->assertStatus(500);

        $this->assertNotSoftDeleted('estudiantes', ['id' => $estudiante->id]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Restaurar -----------------------------------------------------------

    public function test_restaurar_registra_una_operacion_minima_sin_datos_personales(): void
    {
        $admin = $this->usuario('admin');
        $estudiante = $this->estudiante();
        $estudiante->delete();

        $this->actingAs($admin)
            ->patch(route('admin.estudiantes.restaurar', $estudiante->id))
            ->assertRedirect(route('admin.estudiantes.index', ['eliminados' => 1]))
            ->assertSessionHas('success', 'El estudiante se restauró correctamente.');

        $this->assertNotSoftDeleted('estudiantes', ['id' => $estudiante->id]);
        $log = $this->auditorias()->sole();
        $this->assertSame('estudiante_restaurado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Estudiante::class, $log->model_type);
        $this->assertSame($estudiante->id, (int) $log->model_id);
        $this->assertSame(['esquema' => 1, 'operacion' => 'restauracion'], $this->cambios($log));
        $this->assertSinMarcadores($log);
    }

    public function test_restaurar_dos_veces_o_un_estudiante_no_eliminado_no_duplica_la_auditoria(): void
    {
        $vigente = $this->estudiante();
        $eliminado = $this->estudiante(['dni' => '81726356', 'email' => 'otro7731@example.test']);
        $eliminado->delete();
        $this->actingAs($this->usuario('admin'));

        // Un estudiante vigente: mismo comportamiento HTTP, sin operación efectiva.
        $this->patch(route('admin.estudiantes.restaurar', $vigente->id))
            ->assertRedirect(route('admin.estudiantes.index', ['eliminados' => 1]))
            ->assertSessionHas('success');
        $this->assertCount(0, $this->auditorias());

        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))->assertRedirect();
        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))->assertRedirect();

        $this->assertCount(1, $this->auditorias());
    }

    public function test_restaurar_un_id_inexistente_responde_404_sin_auditar(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->patch(route('admin.estudiantes.restaurar', 999999))
            ->assertNotFound();

        $this->assertCount(0, $this->auditorias());
    }

    public function test_restaurar_revierte_si_falla_la_auditoria(): void
    {
        $estudiante = $this->estudiante();
        $estudiante->delete();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->patchJson(route('admin.estudiantes.restaurar', $estudiante->id))->assertStatus(500);

        $this->assertSoftDeleted('estudiantes', ['id' => $estudiante->id]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Permisos ------------------------------------------------------------

    public function test_los_permisos_denegados_no_modifican_ni_auditan(): void
    {
        $vigente = $this->estudiante();
        $eliminado = $this->estudiante(['dni' => '81726356', 'email' => 'otro7731@example.test']);
        $eliminado->delete();
        $secretaria = $this->usuario('secretaria');
        $coordinador = $this->usuario('coordinador');

        $this->actingAs($secretaria)
            ->put(route('admin.estudiantes.update', $vigente), $this->datos(['estado' => 'inactivo']))->assertForbidden();
        $this->delete(route('admin.estudiantes.destroy', $vigente))->assertForbidden();
        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))->assertForbidden();

        $this->actingAs($coordinador)
            ->delete(route('admin.estudiantes.destroy', $vigente))->assertForbidden();
        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))->assertForbidden();

        $this->assertCount(0, $this->auditorias());
        $this->assertDatabaseHas('estudiantes', ['id' => $vigente->id, 'estado' => 'activo', 'deleted_at' => null]);
        $this->assertSoftDeleted('estudiantes', ['id' => $eliminado->id]);
    }

    public function test_sin_permiso_de_crear_no_se_audita(): void
    {
        // Usuario sin rol administrativo: el grupo /admin lo rechaza antes del controlador.
        $this->actingAs(User::factory()->create())
            ->post(route('admin.estudiantes.store'), $this->datos())
            ->assertForbidden();

        $this->assertSame(0, Estudiante::count());
        $this->assertCount(0, $this->auditorias());
    }
}
