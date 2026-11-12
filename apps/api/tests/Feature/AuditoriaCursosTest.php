<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 20, Fase 2b: auditoría de cursos (crear, actualizar, archivar lógicamente, restaurar).
 * Solo precio y cupos (cupos_maximos y cupos_disponibles) llevan valores. SQLite en memoria: no mide
 * bloqueos ni concurrencia de PostgreSQL.
 */
class AuditoriaCursosTest extends TestCase
{
    use RefreshDatabase;

    private const ACCIONES = ['curso_creado', 'curso_actualizado', 'curso_archivado', 'curso_restaurado'];

    /** Marcadores únicos en los campos sin autorización de valor. */
    private const CODIGO = 'COD-MARCA-7731';

    private const NOMBRE = 'NOMBRE-MARCA-7731';

    private const DESCRIPCION = 'DESCRIPCION-MARCA-7731 con posible dato personal';

    private const HORARIO = 'HORARIO-MARCA-7731';

    private const DURACION = 7731;

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

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return array_replace([
            'codigo' => self::CODIGO,
            'nombre' => self::NOMBRE,
            'descripcion' => self::DESCRIPCION,
            'categoria' => 'tecnologia',
            'duracion_horas' => self::DURACION,
            'precio' => 250,
            'modalidad' => 'virtual',
            'horario' => self::HORARIO,
            'cupos_maximos' => 20,
            'estado' => 'activo',
        ], $extra);
    }

    private function curso(array $extra = []): Curso
    {
        $datos = $this->datos($extra);
        $datos['cupos_disponibles'] = $extra['cupos_disponibles'] ?? $datos['cupos_maximos'];

        return Curso::create($datos);
    }

    /** @return Collection<int, AuditLog> */
    private function auditorias(): Collection
    {
        return AuditLog::query()->whereIn('action', self::ACCIONES)->orderBy('id')->get();
    }

    private function crudo(AuditLog $log): string
    {
        return (string) DB::table('audit_logs')->where('id', $log->id)->value('changes');
    }

    /** @return array<string, mixed> */
    private function cambios(AuditLog $log): array
    {
        $decodificado = json_decode($this->crudo($log), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodificado);

        return $decodificado;
    }

    /** @param list<string> $extra */
    private function assertSinMarcadores(AuditLog $log, array $extra = []): void
    {
        $crudo = $this->crudo($log);
        foreach (array_merge([self::CODIGO, self::NOMBRE, self::DESCRIPCION, self::HORARIO, (string) self::DURACION, 'MARCA-7731'], $extra) as $marcador) {
            $this->assertStringNotContainsString($marcador, $crudo, "El valor {$marcador} apareció en changes.");
        }
    }

    private function failAudit(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
    }

    // --- Crear ---------------------------------------------------------------

    public function test_crear_registra_auditoria_con_actor_modelo_y_solo_precio_y_cupos_con_valor(): void
    {
        $admin = $this->usuario('admin');
        $otro = $this->usuario('admin');

        $this->actingAs($admin)
            ->post(route('admin.cursos.store'), $this->datos())
            ->assertRedirect(route('admin.cursos.show', Curso::query()->firstOrNew()))
            ->assertSessionHas('success', 'El curso se registró correctamente.');
        $curso = Curso::where('codigo', self::CODIGO)->firstOrFail();

        $log = $this->auditorias()->sole();
        $this->assertSame('curso_creado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertNotSame($otro->id, $log->user_id);
        $this->assertSame(Curso::class, $log->model_type);
        $this->assertSame($curso->id, (int) $log->model_id);

        $cambios = $this->cambios($log);
        $this->assertSame(1, $cambios['esquema']);
        $this->assertSame(['precio' => '250.00', 'cupos_maximos' => 20, 'cupos_disponibles' => 20], $cambios['valores']);
        $this->assertEqualsCanonicalizing(
            ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'cupos_disponibles', 'estado'],
            $cambios['campos'],
        );
        $this->assertSinMarcadores($log, ['tecnologia', 'virtual']);
    }

    public function test_crear_con_validacion_fallida_no_audita_ni_crea(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->post(route('admin.cursos.store'), $this->datos(['precio' => 'texto', 'cupos_maximos' => 0]))
            ->assertSessionHasErrors(['precio', 'cupos_maximos']);

        $this->assertSame(0, Curso::count());
        $this->assertCount(0, $this->auditorias());
    }

    public function test_crear_revierte_el_alta_si_falla_la_auditoria(): void
    {
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->postJson(route('admin.cursos.store'), $this->datos())->assertStatus(500);

        $this->assertDatabaseMissing('cursos', ['codigo' => self::CODIGO]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Actualizar ----------------------------------------------------------

    public function test_actualizar_precio_y_cupos_registra_valores_anterior_y_nuevo_y_no_expone_otros_campos(): void
    {
        $admin = $this->usuario('admin');
        $curso = $this->curso();

        $this->actingAs($admin)
            ->put(route('admin.cursos.update', $curso), $this->datos([
                'precio' => 300,
                'cupos_maximos' => 40,
                'nombre' => 'OTRO-NOMBRE-MARCA-7731',
                'descripcion' => 'OTRA-DESCRIPCION-MARCA-7731',
                'horario' => 'OTRO-HORARIO-MARCA-7731',
                'categoria' => 'salud',
                'modalidad' => 'presencial',
                'estado' => 'archivado',
                'duracion_horas' => 8842,
            ]))
            ->assertRedirect(route('admin.cursos.show', $curso))
            ->assertSessionHas('success', 'Los datos del curso se actualizaron correctamente.');

        $log = $this->auditorias()->sole();
        $this->assertSame('curso_actualizado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Curso::class, $log->model_type);
        $this->assertSame($curso->id, (int) $log->model_id);

        $cambios = $this->cambios($log);
        $this->assertSame(1, $cambios['esquema']);
        $this->assertEqualsCanonicalizing(
            ['precio', 'cupos_maximos', 'cupos_disponibles', 'nombre', 'descripcion', 'horario', 'categoria', 'modalidad', 'estado', 'duracion_horas'],
            $cambios['campos'],
        );
        $this->assertSame(['precio' => '250.00', 'cupos_maximos' => 20, 'cupos_disponibles' => 20], $cambios['antes']);
        $this->assertSame(['precio' => '300.00', 'cupos_maximos' => 40, 'cupos_disponibles' => 40], $cambios['despues']);
        $this->assertSinMarcadores($log, ['salud', 'presencial', 'archivado', '8842', 'tecnologia', 'virtual']);
        $this->assertDatabaseHas('cursos', ['id' => $curso->id, 'cupos_maximos' => 40, 'cupos_disponibles' => 40]);
    }

    public function test_cambiar_solo_el_precio_registra_solo_el_precio(): void
    {
        $curso = $this->curso();

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.cursos.update', $curso), $this->datos(['precio' => 199.5]))
            ->assertRedirect(route('admin.cursos.show', $curso));

        $cambios = $this->cambios($this->auditorias()->sole());
        $this->assertSame(['precio'], $cambios['campos']);
        $this->assertSame(['precio' => '250.00'], $cambios['antes']);
        $this->assertSame(['precio' => '199.50'], $cambios['despues']);
    }

    public function test_cambiar_los_cupos_registra_el_derivado_con_matriculas_activas(): void
    {
        $curso = $this->curso(['cupos_maximos' => 3, 'cupos_disponibles' => 2]);
        $estudiante = Estudiante::create(['dni' => '70123456', 'nombres' => 'Est', 'apellidos' => 'Mat', 'estado' => 'activo']);
        Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pagada', 'monto_pagado' => 250,
        ]);

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.cursos.update', $curso), $this->datos(['cupos_maximos' => 5]))
            ->assertRedirect(route('admin.cursos.show', $curso));

        $log = $this->auditorias()->sole();
        $cambios = $this->cambios($log);
        $this->assertEqualsCanonicalizing(['cupos_maximos', 'cupos_disponibles'], $cambios['campos']);
        $this->assertSame(['cupos_maximos' => 3, 'cupos_disponibles' => 2], $cambios['antes']);
        $this->assertSame(['cupos_maximos' => 5, 'cupos_disponibles' => 4], $cambios['despues']);
        $this->assertStringNotContainsString('70123456', $this->crudo($log));
    }

    public function test_cambiar_solo_campos_sin_autorizacion_no_guarda_ningun_valor(): void
    {
        $curso = $this->curso();

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.cursos.update', $curso), $this->datos(['nombre' => 'CAMBIO-MARCA-7731', 'horario' => 'CAMBIO-HORARIO-MARCA-7731']))
            ->assertRedirect(route('admin.cursos.show', $curso));

        $log = $this->auditorias()->sole();
        $cambios = $this->cambios($log);
        $this->assertEqualsCanonicalizing(['nombre', 'horario'], $cambios['campos']);
        $this->assertSame([], $cambios['antes']);
        $this->assertSame([], $cambios['despues']);
        $this->assertSinMarcadores($log);
    }

    public function test_actualizar_sin_diferencias_reales_no_audita(): void
    {
        $curso = $this->curso();
        $actualizado = $curso->fresh()->updated_at;

        // 250 frente a «250.00» almacenado y los mismos cupos: no hay diferencia.
        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.cursos.update', $curso), $this->datos())
            ->assertRedirect(route('admin.cursos.show', $curso))
            ->assertSessionHas('success');

        $this->assertCount(0, $this->auditorias());
        $this->assertEquals($actualizado, $curso->fresh()->updated_at);
    }

    public function test_actualizar_con_validacion_fallida_no_audita_ni_modifica(): void
    {
        $curso = $this->curso(['cupos_maximos' => 2, 'cupos_disponibles' => 0]);
        foreach (['70123456', '70123457'] as $dni) {
            $estudiante = Estudiante::create(['dni' => $dni, 'nombres' => 'Est', 'apellidos' => 'Mat', 'estado' => 'activo']);
            Matricula::create([
                'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
                'fecha_matricula' => now()->toDateString(), 'estado' => 'pagada', 'monto_pagado' => 250,
            ]);
        }

        $this->actingAs($this->usuario('admin'))
            ->put(route('admin.cursos.update', $curso), $this->datos(['cupos_maximos' => 1, 'precio' => 999]))
            ->assertSessionHasErrors(['cupos_maximos']);

        $this->assertCount(0, $this->auditorias());
        $this->assertDatabaseHas('cursos', ['id' => $curso->id, 'cupos_maximos' => 2, 'cupos_disponibles' => 0, 'precio' => '250.00']);
    }

    public function test_actualizar_revierte_los_cambios_si_falla_la_auditoria(): void
    {
        $curso = $this->curso();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->putJson(route('admin.cursos.update', $curso), $this->datos(['precio' => 300, 'cupos_maximos' => 40]))->assertStatus(500);

        $this->assertDatabaseHas('cursos', ['id' => $curso->id, 'precio' => '250.00', 'cupos_maximos' => 20, 'cupos_disponibles' => 20]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Archivar ------------------------------------------------------------

    public function test_archivar_registra_una_operacion_minima_y_conserva_la_respuesta(): void
    {
        $admin = $this->usuario('admin');
        $curso = $this->curso();

        $this->actingAs($admin)
            ->delete(route('admin.cursos.destroy', $curso))
            ->assertRedirect(route('admin.cursos.index'))
            ->assertSessionHas('success', 'El curso se archivó correctamente.');

        $this->assertSoftDeleted('cursos', ['id' => $curso->id]);
        $log = $this->auditorias()->sole();
        $this->assertSame('curso_archivado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Curso::class, $log->model_type);
        $this->assertSame($curso->id, (int) $log->model_id);
        $this->assertSame(['esquema' => 1, 'operacion' => 'archivado_logico'], $this->cambios($log));
        $this->assertSinMarcadores($log);
    }

    public function test_archivar_dos_veces_o_un_curso_inexistente_no_duplica_ni_audita(): void
    {
        $curso = $this->curso();
        $this->actingAs($this->usuario('admin'));

        $this->delete(route('admin.cursos.destroy', $curso))->assertRedirect();
        $this->delete(route('admin.cursos.destroy', $curso->id))->assertNotFound();
        $this->delete(route('admin.cursos.destroy', 999999))->assertNotFound();

        $this->assertCount(1, $this->auditorias());
    }

    public function test_archivar_revierte_si_falla_la_auditoria(): void
    {
        $curso = $this->curso();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->deleteJson(route('admin.cursos.destroy', $curso))->assertStatus(500);

        $this->assertNotSoftDeleted('cursos', ['id' => $curso->id]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Restaurar -----------------------------------------------------------

    public function test_restaurar_registra_una_operacion_minima_y_conserva_la_respuesta(): void
    {
        $admin = $this->usuario('admin');
        $curso = $this->curso();
        $curso->delete();

        $this->actingAs($admin)
            ->patch(route('admin.cursos.restaurar', $curso->id))
            ->assertRedirect(route('admin.cursos.index', ['eliminados' => 1]))
            ->assertSessionHas('success', 'El curso se restauró correctamente.');

        $this->assertNotSoftDeleted('cursos', ['id' => $curso->id]);
        $log = $this->auditorias()->sole();
        $this->assertSame('curso_restaurado', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Curso::class, $log->model_type);
        $this->assertSame($curso->id, (int) $log->model_id);
        $this->assertSame(['esquema' => 1, 'operacion' => 'restauracion'], $this->cambios($log));
        $this->assertSinMarcadores($log);
    }

    public function test_restaurar_dos_veces_o_un_curso_no_archivado_no_duplica_la_auditoria(): void
    {
        $vigente = $this->curso();
        $archivado = $this->curso(['codigo' => 'COD-OTRO-7731']);
        $archivado->delete();
        $this->actingAs($this->usuario('admin'));

        $this->patch(route('admin.cursos.restaurar', $vigente->id))
            ->assertRedirect(route('admin.cursos.index', ['eliminados' => 1]))
            ->assertSessionHas('success');
        $this->assertCount(0, $this->auditorias());

        $this->patch(route('admin.cursos.restaurar', $archivado->id))->assertRedirect();
        $this->patch(route('admin.cursos.restaurar', $archivado->id))->assertRedirect();

        $this->assertCount(1, $this->auditorias());
    }

    public function test_restaurar_un_id_inexistente_responde_404_sin_auditar(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->patch(route('admin.cursos.restaurar', 999999))
            ->assertNotFound();

        $this->assertCount(0, $this->auditorias());
    }

    public function test_restaurar_revierte_si_falla_la_auditoria(): void
    {
        $curso = $this->curso();
        $curso->delete();
        $this->actingAs($this->usuario('admin'));
        $this->failAudit();

        $this->patchJson(route('admin.cursos.restaurar', $curso->id))->assertStatus(500);

        $this->assertSoftDeleted('cursos', ['id' => $curso->id]);
        $this->assertSame(0, AuditLog::count());
    }

    // --- Permisos ------------------------------------------------------------

    public function test_los_permisos_denegados_no_modifican_ni_auditan(): void
    {
        $vigente = $this->curso();
        $archivado = $this->curso(['codigo' => 'COD-OTRO-7731']);
        $archivado->delete();

        $this->actingAs($this->usuario('secretaria'))
            ->post(route('admin.cursos.store'), $this->datos(['codigo' => 'COD-NUEVO-7731']))->assertForbidden();
        $this->put(route('admin.cursos.update', $vigente), $this->datos(['precio' => 999]))->assertForbidden();
        $this->delete(route('admin.cursos.destroy', $vigente))->assertForbidden();
        $this->patch(route('admin.cursos.restaurar', $archivado->id))->assertForbidden();

        $this->actingAs($this->usuario('coordinador'))
            ->delete(route('admin.cursos.destroy', $vigente))->assertForbidden();
        $this->patch(route('admin.cursos.restaurar', $archivado->id))->assertForbidden();

        $this->assertCount(0, $this->auditorias());
        $this->assertDatabaseMissing('cursos', ['codigo' => 'COD-NUEVO-7731']);
        $this->assertDatabaseHas('cursos', ['id' => $vigente->id, 'precio' => '250.00', 'deleted_at' => null]);
        $this->assertSoftDeleted('cursos', ['id' => $archivado->id]);
    }
}
