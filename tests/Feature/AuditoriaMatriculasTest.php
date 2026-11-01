<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 21.2 · Fases 2 y 3: auditoría de crear (store), actualizar (update), anular (anular), eliminar (destroy)
 * y restaurar (restaurar) matrículas.
 *
 * SQLite en memoria: demuestra la lógica y el rollback transaccional, no la concurrencia real de PostgreSQL.
 * Los identificadores M01… hacen referencia a la matriz de pruebas de la Fase 0 (PROJECT_CONTEXT.md).
 */
class AuditoriaMatriculasTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVADO = 'PRIVATE-NOTE DNI 71000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------------------------------

    /** M01 + M12 + M13 */
    #[DataProvider('estadosVigentes')]
    public function test_store_audita_la_creacion_con_actor_valores_efecto_y_sin_datos_privados(string $estado): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $antes = $this->cupos($curso);
        $notificaciones = MatriculaNotification::count();

        $this->actingAs($admin)
            ->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, $estado, ['monto_pagado' => 150.5, 'observaciones' => self::PRIVADO]))
            ->assertRedirect();

        $matricula = Matricula::sole();
        $registro = $this->unicoEvento('matricula_creada');
        $this->assertSame(Matricula::class, $registro->model_type);
        $this->assertSame($matricula->id, (int) $registro->model_id);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(1, $registro->changes['esquema']);
        $this->assertSame('ocupa', $registro->changes['efecto_cupo']);
        $this->assertSame([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28',
            'estado' => $estado,
            'monto_pagado' => '150.50',
        ], $registro->changes['valores']);
        $this->assertContains('observaciones', $registro->changes['campos']);
        $this->assertNoHayDatosPrivados();
        $this->assertEfecto($registro, $antes);
        $this->assertSame(1, MatriculaNotification::count() - $notificaciones);
        $this->assertSame(1, AuditLog::count());
    }

    /** @return array<string, array{0: string}> */
    public static function estadosVigentes(): array
    {
        return ['pendiente' => ['pendiente'], 'pagada' => ['pagada']];
    }

    /** M02 */
    public function test_store_de_una_matricula_nacida_anulada_audita_con_efecto_ninguno_y_sin_notificacion(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'anulada'))->assertRedirect();

        $registro = $this->unicoEvento('matricula_creada');
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
        $this->assertSame('anulada', $registro->changes['valores']['estado']);
        $this->assertEfecto($registro, $antes);
        $this->assertSame(0, MatriculaNotification::count());
    }

    public function test_store_conserva_redireccion_y_mensaje(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);

        $respuesta = $this->actingAs($admin)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'));

        $respuesta->assertRedirect(route('admin.matriculas.show', Matricula::sole()));
        $respuesta->assertSessionHas('success', 'La matrícula se registró correctamente.');
    }

    /** D9: un formulario HTML envía los ids como cadena; no debe fallar ni auditar tipos mezclados. */
    public function test_store_con_identificadores_como_cadena_funciona_y_audita_enteros(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);

        $respuesta = $this->actingAs($admin)
            ->post(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente', [
                'estudiante_id' => (string) $estudiante->id,
                'curso_id' => (string) $curso->id,
            ]));

        $respuesta->assertRedirect();
        $respuesta->assertSessionHasNoErrors();
        $this->assertSame(1, Matricula::count());

        $valores = $this->unicoEvento('matricula_creada')->changes['valores'];
        $this->assertSame($estudiante->id, $valores['estudiante_id']);
        $this->assertSame($curso->id, $valores['curso_id']);
        $this->assertSame(1, $curso->fresh()->cupos_maximos - $curso->fresh()->cupos_disponibles);
    }

    /** Ningún aviso externo sale antes del commit: la intención es solo una fila en la misma transacción. */
    public function test_store_no_despacha_colas_ni_http_durante_la_transaccion(): void
    {
        Bus::fake();
        Http::fake();
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);

        $this->actingAs($admin)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'))->assertRedirect();

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        $this->assertSame(1, MatriculaNotification::count());
        $this->assertSame('held', MatriculaNotification::sole()->status);
    }

    /** M12: cada evento lleva el actor de quien ejecuta. */
    public function test_store_registra_el_actor_de_cada_usuario(): void
    {
        $primero = $this->adminUser();
        $segundo = User::factory()->create();
        $segundo->assignRole('admin');
        [$estudiante, $curso] = $this->escenario(3);
        $otro = Estudiante::create(['dni' => '71000002', 'nombres' => 'Luis', 'apellidos' => 'Otro', 'estado' => 'activo']);

        $this->actingAs($primero)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'))->assertRedirect();
        $this->actingAs($segundo)->postJson(route('admin.matriculas.store'), $this->datos($otro, $curso, 'pendiente'))->assertRedirect();

        $this->assertSame(
            [$primero->id, $segundo->id],
            AuditLog::where('action', 'matricula_creada')->orderBy('id')->pluck('user_id')->all(),
        );
    }

    /** M14 */
    public function test_store_ignora_campos_enviados_de_mas(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);

        $this->actingAs($admin)
            ->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente', [
                'dni' => '99999999', 'password' => 'SECRETO-1', 'remember_token' => 'TOKEN-SECRETO', 'campo_interno' => 'X-INTERNO',
            ]))
            ->assertRedirect();

        $json = $this->auditoriaComoTexto();
        foreach (['99999999', 'SECRETO-1', 'TOKEN-SECRETO', 'X-INTERNO'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json);
        }
        $this->assertSame(1, Matricula::count());
    }

    /** M16 */
    public function test_store_revierte_todo_si_falla_la_auditoria(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $antes = $this->cupos($curso);
        $this->fallarAuditoria();
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'));
            $this->fail('Se esperaba el fallo simulado de auditoría.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de auditoría simulado', $error->getMessage());
        }

        $this->assertSame(0, Matricula::count());
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, MatriculaNotification::count());
        $this->assertSame(0, AuditLog::count());
    }

    /** M18 */
    public function test_store_sin_permiso_devuelve_403_y_no_audita(): void
    {
        $this->adminUser();
        Role::findByName('secretaria')->revokePermissionTo('matriculas.crear');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');
        [$estudiante, $curso] = $this->escenario(2);

        $this->actingAs($secretaria)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'))->assertForbidden();

        $this->assertSame(0, Matricula::count());
        $this->assertSame(0, AuditLog::count());
    }

    /** M19 */
    #[DataProvider('condicionesQueImpidenCrear')]
    public function test_store_rechazado_por_validacion_no_audita_ni_mueve_cupos(string $condicion): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(1);
        match ($condicion) {
            'estudiante inactivo' => $estudiante->update(['estado' => 'inactivo']),
            'curso archivado' => $curso->update(['estado' => 'archivado']),
            'sin cupo' => $curso->update(['cupos_disponibles' => 0]),
            'duplicado' => $this->matriculaVigente($estudiante, $curso),
            default => null,
        };
        $antes = $this->cupos($curso);
        $matriculas = Matricula::count();

        $this->actingAs($admin)->postJson(route('admin.matriculas.store'), $this->datos($estudiante, $curso, 'pendiente'))->assertUnprocessable();

        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame($matriculas, Matricula::count());
        $this->assertSame(0, AuditLog::count());
    }

    /** @return array<string, array{0: string}> */
    public static function condicionesQueImpidenCrear(): array
    {
        return [
            'estudiante inactivo' => ['estudiante inactivo'],
            'curso archivado' => ['curso archivado'],
            'sin cupo' => ['sin cupo'],
            'duplicado' => ['duplicado'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // anular
    // ---------------------------------------------------------------------------------------------

    /** M07 + M12 + M13 */
    #[DataProvider('estadosVigentes')]
    public function test_anular_audita_una_sola_vez_con_efecto_libera(string $estado): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, $estado, ['observaciones' => self::PRIVADO]);
        $antes = $this->cupos($curso);

        $respuesta = $this->actingAs($admin)->patchJson(route('admin.matriculas.anular', $matricula));

        $respuesta->assertRedirect(route('admin.matriculas.index'));
        $respuesta->assertSessionHas('success', 'La matrícula se anuló correctamente.');
        $registro = $this->unicoEvento('matricula_anulada');
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($matricula->id, (int) $registro->model_id);
        $this->assertSame(['estado'], $registro->changes['campos']);
        $this->assertSame(['estado' => $estado], $registro->changes['antes']);
        $this->assertSame(['estado' => 'anulada'], $registro->changes['despues']);
        $this->assertSame('libera', $registro->changes['efecto_cupo']);
        $this->assertEfecto($registro, $antes);
        $this->assertNoHayDatosPrivados();
        $this->assertSame(1, AuditLog::count());
    }

    /** D4 + D12 + M08 */
    public function test_anular_dos_veces_no_audita_la_segunda_conserva_el_mensaje_y_no_devuelve_cupo_otra_vez(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);

        $this->actingAs($admin)->patchJson(route('admin.matriculas.anular', $matricula))->assertRedirect();
        $cupos = $this->cupos($curso);

        $segunda = $this->patchJson(route('admin.matriculas.anular', $matricula));

        $segunda->assertRedirect(route('admin.matriculas.index'));
        $segunda->assertSessionHas('success', 'La matrícula se anuló correctamente.');
        $this->assertSame($cupos, $this->cupos($curso));
        $this->assertSame(1, AuditLog::count());
    }

    public function test_anular_una_matricula_que_ya_nacio_anulada_no_audita(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = Matricula::create($this->datos($estudiante, $curso, 'anulada'));
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->patchJson(route('admin.matriculas.anular', $matricula))->assertRedirect();

        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());
    }

    /** M12 */
    public function test_anular_registra_el_actor_de_cada_usuario(): void
    {
        $primero = $this->adminUser();
        $segundo = User::factory()->create();
        $segundo->assignRole('admin');
        [$estudiante, $curso] = $this->escenario(3);
        $otro = Estudiante::create(['dni' => '71000002', 'nombres' => 'Luis', 'apellidos' => 'Otro', 'estado' => 'activo']);
        $a = $this->matriculaVigente($estudiante, $curso);
        $b = $this->matriculaVigente($otro, $curso);

        $this->actingAs($primero)->patchJson(route('admin.matriculas.anular', $a))->assertRedirect();
        $this->actingAs($segundo)->patchJson(route('admin.matriculas.anular', $b))->assertRedirect();

        $this->assertSame([$primero->id, $segundo->id], AuditLog::where('action', 'matricula_anulada')->orderBy('id')->pluck('user_id')->all());
    }

    /** M18 */
    public function test_anular_sin_permiso_devuelve_403_y_no_audita(): void
    {
        $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');
        $antes = $this->cupos($curso);

        $this->actingAs($coordinador)->patchJson(route('admin.matriculas.anular', $matricula))->assertForbidden();

        $this->assertSame('pendiente', $matricula->fresh()->estado);
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());
    }

    /** M20: otro operador anuló la matrícula entre el binding de la ruta y la lectura bajo bloqueo. */
    public function test_anular_con_un_binding_obsoleto_no_audita_si_la_fila_bloqueada_ya_estaba_anulada(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $hecho = false;
        Matricula::retrieved(function () use ($matricula, $curso, &$hecho): void {
            if ($hecho) {
                return;
            }
            $hecho = true;
            DB::table('matriculas')->where('id', $matricula->id)->update(['estado' => 'anulada']);
            DB::table('cursos')->where('id', $curso->id)->increment('cupos_disponibles');
        });
        $this->actingAs($admin);

        $this->patchJson(route('admin.matriculas.anular', $matricula))->assertRedirect();

        $this->assertSame('anulada', $matricula->fresh()->estado);
        $this->assertSame($curso->cupos_maximos, $curso->fresh()->cupos_disponibles);
        $this->assertSame(0, AuditLog::count());
    }

    // ---------------------------------------------------------------------------------------------
    // update
    // ---------------------------------------------------------------------------------------------

    /** M03 */
    public function test_update_audita_cambios_reales_de_estado_monto_y_fecha(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pendiente', ['monto_pagado' => 100]);
        $antes = $this->cupos($curso);

        $respuesta = $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pagada', [
            'monto_pagado' => 80, 'fecha_matricula' => '2026-10-30',
        ]));

        $respuesta->assertRedirect(route('admin.matriculas.show', $matricula));
        $respuesta->assertSessionHas('success', 'La matrícula se actualizó correctamente.');
        $registro = $this->unicoEvento('matricula_actualizada');
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertEqualsCanonicalizing(['estado', 'monto_pagado', 'fecha_matricula'], $registro->changes['campos']);
        $this->assertSameSinOrden(['estado' => 'pendiente', 'monto_pagado' => '100.00', 'fecha_matricula' => '2026-10-28'], $registro->changes['antes']);
        $this->assertSameSinOrden(['estado' => 'pagada', 'monto_pagado' => '80.00', 'fecha_matricula' => '2026-10-30'], $registro->changes['despues']);
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
        $this->assertEfecto($registro, $antes);
        $this->assertSame(1, AuditLog::count());
    }

    /** M04 + D9 (ids como cadena, tipos coherentes en el JSON auditado) */
    public function test_update_de_traslado_audita_curso_anterior_y_nuevo_con_efecto_traslada(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $destino = $this->curso('DESTINO', 2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pagada');
        $antes = $this->cupos($curso, $destino);

        $this->actingAs($admin)->put(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $destino, 'pagada', [
            'estudiante_id' => (string) $estudiante->id,
            'curso_id' => (string) $destino->id,
        ]))->assertRedirect();

        $registro = $this->unicoEvento('matricula_actualizada');
        $this->assertSame(['curso_id'], $registro->changes['campos']);
        $this->assertSame(['curso_id' => $curso->id], $registro->changes['antes']);
        $this->assertSame(['curso_id' => $destino->id], $registro->changes['despues']);
        $this->assertSame('traslada', $registro->changes['efecto_cupo']);
        $this->assertEfecto($registro, $antes);
    }

    /** M05 */
    #[DataProvider('reactivaciones')]
    public function test_update_que_reactiva_una_anulada_audita_ocupa_y_no_crea_otra_notificacion(bool $otroCurso): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $destino = $this->curso('DESTINO', 2);
        $matricula = Matricula::create($this->datos($estudiante, $curso, 'anulada'));
        $objetivo = $otroCurso ? $destino : $curso;
        $antes = $this->cupos($curso, $destino);
        $notificaciones = MatriculaNotification::count();

        $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $objetivo, 'pendiente'))->assertRedirect();

        $registro = $this->unicoEvento('matricula_actualizada');
        $this->assertSame('ocupa', $registro->changes['efecto_cupo']);
        $this->assertSame('anulada', $registro->changes['antes']['estado']);
        $this->assertSame('pendiente', $registro->changes['despues']['estado']);
        $this->assertEfecto($registro, $antes);
        $this->assertSame($notificaciones, MatriculaNotification::count());
    }

    /** @return array<string, array{0: bool}> */
    public static function reactivaciones(): array
    {
        return ['mismo curso' => [false], 'otro curso' => [true]];
    }

    /** M06 + D3: un solo evento matricula_anulada con todas las diferencias permitidas. */
    public function test_update_a_anulada_con_otros_cambios_genera_un_unico_evento_anulada(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $destino = $this->curso('DESTINO', 2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pagada', ['monto_pagado' => 100]);
        $antes = $this->cupos($curso, $destino);

        $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $destino, 'anulada', [
            'monto_pagado' => 0, 'observaciones' => self::PRIVADO,
        ]))->assertRedirect();

        $this->assertSame(1, AuditLog::count());
        $this->assertSame(0, AuditLog::where('action', 'matricula_actualizada')->count());
        $registro = $this->unicoEvento('matricula_anulada');
        $this->assertEqualsCanonicalizing(['estado', 'curso_id', 'monto_pagado', 'observaciones'], $registro->changes['campos']);
        $this->assertSameSinOrden(['estado' => 'pagada', 'curso_id' => $curso->id, 'monto_pagado' => '100.00'], $registro->changes['antes']);
        $this->assertSameSinOrden(['estado' => 'anulada', 'curso_id' => $destino->id, 'monto_pagado' => '0.00'], $registro->changes['despues']);
        $this->assertSame('libera', $registro->changes['efecto_cupo']);
        $this->assertEfecto($registro, $antes);
        $this->assertNoHayDatosPrivados();
    }

    /** M08 */
    public function test_update_sin_cambios_reales_no_audita_aunque_los_formatos_difieran(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pendiente', ['monto_pagado' => 100, 'observaciones' => 'igual']);
        $antes = $this->cupos($curso);

        $respuesta = $this->actingAs($admin)->put(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente', [
            'estudiante_id' => (string) $estudiante->id,
            'curso_id' => (string) $curso->id,
            'monto_pagado' => '100',
            'observaciones' => 'igual',
        ]));

        $respuesta->assertRedirect(route('admin.matriculas.show', $matricula));
        $respuesta->assertSessionHas('success', 'La matrícula se actualizó correctamente.');
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());
    }

    /** D10-a: una fecha con hora de la misma fecha no es un cambio real. */
    public function test_update_con_fecha_con_hora_de_la_misma_fecha_no_audita(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);

        $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente', [
            'fecha_matricula' => '2026-10-28 10:30:00',
        ]))->assertRedirect();

        $this->assertSame('2026-10-28', $matricula->fresh()->fecha_matricula->toDateString());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_update_a_anulada_de_una_matricula_ya_anulada_no_audita(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = Matricula::create($this->datos($estudiante, $curso, 'anulada'));
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'anulada'))->assertRedirect();

        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());
    }

    /** M12 + M13 + M14 */
    public function test_update_registra_el_actor_no_guarda_observaciones_ni_campos_ajenos(): void
    {
        $primero = $this->adminUser();
        $segundo = User::factory()->create();
        $segundo->assignRole('admin');
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);

        $this->actingAs($primero)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pagada', [
            'observaciones' => self::PRIVADO, 'dni' => '99999999', 'password' => 'SECRETO-1', 'remember_token' => 'TOKEN-SECRETO',
        ]))->assertRedirect();
        $this->actingAs($segundo)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente'))->assertRedirect();

        $this->assertSame([$primero->id, $segundo->id], AuditLog::where('action', 'matricula_actualizada')->orderBy('id')->pluck('user_id')->all());
        $primera = AuditLog::orderBy('id')->first();
        $this->assertContains('observaciones', $primera->changes['campos']);
        $this->assertNoHayDatosPrivados();
        foreach (['99999999', 'SECRETO-1', 'TOKEN-SECRETO'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $this->auditoriaComoTexto());
        }
    }

    /** M18 */
    public function test_update_conserva_los_permisos_por_rol(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pagada');
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');
        $antes = $this->cupos($curso);

        $this->actingAs($secretaria)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente'))->assertForbidden();
        $this->actingAs($coordinador)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'anulada'))->assertForbidden();
        $this->assertSame('pagada', $matricula->fresh()->estado);
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());

        $this->actingAs($coordinador)->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente'))->assertRedirect();
        $this->assertSame($coordinador->id, $this->unicoEvento('matricula_actualizada')->user_id);
        $this->assertNotNull($admin);
    }

    /** M19 */
    #[DataProvider('condicionesQueImpidenActualizar')]
    public function test_update_rechazado_no_audita_ni_mueve_cupos(string $condicion): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $destino = $this->curso('DESTINO', 1);
        $otro = Estudiante::create(['dni' => '71000002', 'nombres' => 'Luis', 'apellidos' => 'Otro', 'estado' => 'activo']);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $objetivo = $destino;
        $quien = $estudiante;
        match ($condicion) {
            'destino sin cupo' => $destino->update(['cupos_disponibles' => 0]),
            'destino archivado' => $destino->update(['estado' => 'archivado']),
            'destino eliminado' => $destino->delete(),
            'duplicado' => $this->matriculaVigente($estudiante, $destino),
            'estudiante inactivo' => $estudiante->update(['estado' => 'inactivo']),
            default => null,
        };
        $antes = $this->cupos($curso, $destino);
        $fila = DB::table('matriculas')->where('id', $matricula->id)->first();

        $this->actingAs($admin)->putJson(route('admin.matriculas.update', $matricula), $this->datos($quien, $objetivo, 'pendiente'));

        $this->assertEquals($fila, DB::table('matriculas')->where('id', $matricula->id)->first());
        $this->assertSame($antes, $this->cupos($curso, $destino));
        $this->assertSame(0, AuditLog::where('model_type', Matricula::class)->count());
        $this->assertNotNull($otro);
    }

    /** @return array<string, array{0: string}> */
    public static function condicionesQueImpidenActualizar(): array
    {
        return [
            'destino sin cupo' => ['destino sin cupo'],
            'destino archivado' => ['destino archivado'],
            'destino eliminado' => ['destino eliminado'],
            'duplicado' => ['duplicado'],
            'estudiante inactivo' => ['estudiante inactivo'],
        ];
    }

    /** M20: otro operador cambió la fila después de validar; el «antes» auditado es el de la fila bloqueada. */
    public function test_update_audita_el_estado_real_de_la_fila_bloqueada_y_no_el_del_binding(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pendiente');
        $this->actingAs($admin);
        $this->app->resolving(UpdateMatriculaRequest::class, function () use ($matricula): void {
            DB::table('matriculas')->where('id', $matricula->id)->update(['estado' => 'pagada', 'monto_pagado' => 55]);
        });

        $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pagada', ['monto_pagado' => 70]))->assertRedirect();

        $registro = $this->unicoEvento('matricula_actualizada');
        $this->assertSame(['monto_pagado'], $registro->changes['campos']);
        $this->assertSame(['monto_pagado' => '55.00'], $registro->changes['antes']);
        $this->assertSame(['monto_pagado' => '70.00'], $registro->changes['despues']);
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
    }

    public function test_update_a_anulada_sobre_una_fila_que_otro_operador_ya_anulo_no_libera_ni_audita_de_nuevo(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pendiente');
        $this->actingAs($admin);
        $this->app->resolving(UpdateMatriculaRequest::class, function () use ($matricula, $curso): void {
            DB::table('matriculas')->where('id', $matricula->id)->update(['estado' => 'anulada']);
            DB::table('cursos')->where('id', $curso->id)->increment('cupos_disponibles');
        });

        $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'anulada'))->assertRedirect();

        $this->assertSame($curso->cupos_maximos, $curso->fresh()->cupos_disponibles);
        $this->assertSame(0, AuditLog::count());
    }

    // ---------------------------------------------------------------------------------------------
    // M16 — rollback transaccional de update y anular
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function operacionesConRollback(): array
    {
        return [
            'anular' => ['anular'],
            'update de datos' => ['update datos'],
            'update traslado' => ['update traslado'],
            'update a anulada' => ['update anulada'],
            'update reactivacion' => ['update reactivacion'],
        ];
    }

    #[DataProvider('operacionesConRollback')]
    public function test_update_y_anular_revierten_todo_si_falla_la_auditoria(string $operacion): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $destino = $this->curso('DESTINO', 2);
        $matricula = $operacion === 'update reactivacion'
            ? Matricula::create($this->datos($estudiante, $curso, 'anulada'))
            : $this->matriculaVigente($estudiante, $curso, 'pagada');
        $fila = DB::table('matriculas')->where('id', $matricula->id)->first();
        $antes = $this->cupos($curso, $destino);
        $notificaciones = MatriculaNotification::count();
        $this->actingAs($admin);
        $this->fallarAuditoria();
        $this->withoutExceptionHandling();

        try {
            match ($operacion) {
                'anular' => $this->patchJson(route('admin.matriculas.anular', $matricula)),
                'update datos' => $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente', ['monto_pagado' => 5])),
                'update traslado' => $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $destino, 'pagada')),
                'update anulada' => $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $destino, 'anulada')),
                default => $this->putJson(route('admin.matriculas.update', $matricula), $this->datos($estudiante, $curso, 'pendiente')),
            };
            $this->fail('Se esperaba el fallo simulado de auditoría.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de auditoría simulado', $error->getMessage());
        }

        $this->assertEquals($fila, DB::table('matriculas')->where('id', $matricula->id)->first());
        $this->assertSame($antes, $this->cupos($curso, $destino));
        $this->assertSame($notificaciones, MatriculaNotification::count());
        $this->assertSame(0, AuditLog::count());
    }

    // ---------------------------------------------------------------------------------------------
    // Fase 3 — destroy (eliminación lógica) y restaurar
    // ---------------------------------------------------------------------------------------------

    /** M09 + M12 + M13 */
    #[DataProvider('estadosVigentes')]
    public function test_destroy_de_una_vigente_audita_la_eliminacion_con_efecto_libera(string $estado): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, $estado, ['monto_pagado' => 90, 'observaciones' => self::PRIVADO]);
        $antes = $this->cupos($curso);

        $respuesta = $this->actingAs($admin)->deleteJson(route('admin.matriculas.destroy', $matricula));

        $respuesta->assertRedirect(route('admin.matriculas.index'));
        $respuesta->assertSessionHas('success', 'La matrícula se eliminó correctamente.');
        $this->assertSoftDeleted($matricula);
        $registro = $this->unicoEvento('matricula_eliminada');
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(Matricula::class, $registro->model_type);
        $this->assertSame($matricula->id, (int) $registro->model_id);
        $this->assertSame([
            'esquema' => 1,
            'operacion' => 'eliminacion_logica',
            'efecto_cupo' => 'libera',
            'curso_id' => $curso->id,
            'estado' => $estado,
        ], $registro->changes);
        $this->assertEfecto($registro, $antes);
        $this->assertNoHayDatosPrivados();
        $this->assertStringNotContainsString('90', json_encode($registro->changes, JSON_THROW_ON_ERROR));
        $this->assertSame(1, AuditLog::count());
    }

    /** M09: eliminar una anulada no devuelve cupo. */
    public function test_destroy_de_una_anulada_audita_con_efecto_ninguno(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = Matricula::create($this->datos($estudiante, $curso, 'anulada'));
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();

        $this->assertSoftDeleted($matricula);
        $registro = $this->unicoEvento('matricula_eliminada');
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
        $this->assertSame('anulada', $registro->changes['estado']);
        $this->assertEfecto($registro, $antes);
    }

    /** Se conserva el contrato actual: se libera el cupo aunque el curso ya esté eliminado lógicamente. */
    public function test_destroy_libera_y_audita_aunque_el_curso_este_eliminado_logicamente(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $curso->delete();
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();

        $registro = $this->unicoEvento('matricula_eliminada');
        $this->assertSame('libera', $registro->changes['efecto_cupo']);
        $this->assertEfecto($registro, $antes);
    }

    /** M10 + M12 + M13 */
    #[DataProvider('estadosVigentes')]
    public function test_restaurar_una_vigente_audita_con_efecto_ocupa(string $estado): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaEliminada($estudiante, $curso, $estado, ['monto_pagado' => 90, 'observaciones' => self::PRIVADO]);
        $antes = $this->cupos($curso);

        $respuesta = $this->actingAs($admin)->patchJson(route('admin.matriculas.restaurar', $matricula->id));

        $respuesta->assertRedirect(route('admin.matriculas.index'));
        $respuesta->assertSessionHas('success', 'La matrícula se restauró correctamente.');
        $this->assertNotSoftDeleted($matricula);
        $registro = $this->unicoEvento('matricula_restaurada');
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($matricula->id, (int) $registro->model_id);
        $this->assertSame([
            'esquema' => 1,
            'operacion' => 'restauracion',
            'efecto_cupo' => 'ocupa',
            'curso_id' => $curso->id,
            'estado' => $estado,
        ], $registro->changes);
        $this->assertEfecto($registro, $antes);
        $this->assertNoHayDatosPrivados();
        $this->assertSame(1, AuditLog::count());
    }

    /** M10: restaurar una anulada no ocupa cupo, aunque el curso o el estudiante estén eliminados o haya otra vigente. */
    #[DataProvider('contextosDeRestauracionHistorica')]
    public function test_restaurar_una_anulada_audita_con_efecto_ninguno(string $contexto): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaEliminada($estudiante, $curso, 'anulada');
        match ($contexto) {
            'curso eliminado' => $curso->delete(),
            'estudiante eliminado' => $estudiante->delete(),
            'duplicado vigente' => $this->matriculaVigente($estudiante, $curso),
            default => null,
        };
        $antes = $this->cupos($curso);

        $this->actingAs($admin)->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertRedirect();

        $this->assertNotSoftDeleted($matricula);
        $registro = $this->unicoEvento('matricula_restaurada');
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
        $this->assertSame('anulada', $registro->changes['estado']);
        $this->assertEfecto($registro, $antes);
    }

    /** @return array<string, array{0: string}> */
    public static function contextosDeRestauracionHistorica(): array
    {
        return [
            'sin particularidades' => ['normal'],
            'curso eliminado' => ['curso eliminado'],
            'estudiante eliminado' => ['estudiante eliminado'],
            'duplicado vigente' => ['duplicado vigente'],
        ];
    }

    /** M11: eliminar y restaurar deja los cupos como estaban y cada operación registra exactamente un evento. */
    public function test_eliminar_y_restaurar_registran_dos_eventos_y_los_cupos_vuelven_al_inicio(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pagada');
        $inicio = $this->cupos($curso);

        $this->actingAs($admin)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();
        $this->assertSame([$curso->id => $inicio[$curso->id] + 1], $this->cupos($curso));
        $this->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertRedirect();

        $this->assertSame($inicio, $this->cupos($curso));
        $this->assertSame(['matricula_eliminada', 'matricula_restaurada'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(
            ['libera', 'ocupa'],
            AuditLog::orderBy('id')->get()->map(fn (AuditLog $a) => $a->changes['efecto_cupo'])->all(),
        );
    }

    /** M12 */
    public function test_destroy_y_restaurar_registran_el_actor_de_cada_usuario(): void
    {
        // El primer usuario creado (id 1) no opera: así un actor «cableado» a 1 no podría pasar desapercibido.
        $this->adminUser();
        $segundo = User::factory()->create();
        $segundo->assignRole('admin');
        $tercero = User::factory()->create();
        $tercero->assignRole('admin');
        [$estudiante, $curso] = $this->escenario(3);
        $matricula = $this->matriculaVigente($estudiante, $curso);

        $this->actingAs($segundo)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();
        $this->actingAs($tercero)->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertRedirect();

        $this->assertGreaterThan(1, $segundo->id);
        $this->assertSame($segundo->id, $this->unicoEvento('matricula_eliminada')->user_id);
        $this->assertSame($tercero->id, $this->unicoEvento('matricula_restaurada')->user_id);
    }

    /** No hay eventos duplicados: una segunda eliminación o una restauración de una no eliminada responden 404 y no auditan. */
    public function test_repetir_destroy_o_restaurar_una_no_eliminada_no_genera_eventos_extra(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $this->actingAs($admin);

        $this->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertNotFound();
        $this->assertSame(0, AuditLog::count());

        $this->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();
        $this->deleteJson(route('admin.matriculas.destroy', $matricula->id))->assertNotFound();

        $this->assertSame(1, AuditLog::count());
        $this->assertSame(1, AuditLog::where('action', 'matricula_eliminada')->count());
    }

    /** M16 */
    #[DataProvider('operacionesDeBorradoConRollback')]
    public function test_destroy_y_restaurar_revierten_todo_si_falla_la_auditoria(string $operacion, string $estado): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $operacion === 'destroy'
            ? $this->matriculaVigente($estudiante, $curso, $estado)
            : $this->matriculaEliminada($estudiante, $curso, $estado);
        $fila = DB::table('matriculas')->where('id', $matricula->id)->first();
        $antes = $this->cupos($curso);
        $notificaciones = MatriculaNotification::count();
        $this->actingAs($admin);
        $this->fallarAuditoria();
        $this->withoutExceptionHandling();

        try {
            $operacion === 'destroy'
                ? $this->deleteJson(route('admin.matriculas.destroy', $matricula))
                : $this->patchJson(route('admin.matriculas.restaurar', $matricula->id));
            $this->fail('Se esperaba el fallo simulado de auditoría.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de auditoría simulado', $error->getMessage());
        }

        $this->assertEquals($fila, DB::table('matriculas')->where('id', $matricula->id)->first());
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame($notificaciones, MatriculaNotification::count());
        $this->assertSame(0, AuditLog::count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function operacionesDeBorradoConRollback(): array
    {
        return [
            'destroy vigente' => ['destroy', 'pagada'],
            'destroy anulada' => ['destroy', 'anulada'],
            'restaurar vigente' => ['restaurar', 'pendiente'],
            'restaurar anulada' => ['restaurar', 'anulada'],
        ];
    }

    /** M18 */
    public function test_destroy_y_restaurar_conservan_los_permisos_por_rol(): void
    {
        $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso);
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');
        $antes = $this->cupos($curso);

        // El coordinador no puede eliminar; la secretaria no puede eliminar ni restaurar: 403 y sin auditoría.
        $this->actingAs($coordinador)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertForbidden();
        $this->actingAs($secretaria)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertForbidden();
        $this->assertNotSoftDeleted($matricula);
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame(0, AuditLog::count());

        // El administrador elimina; el coordinador sí puede restaurar (contrato actual) y se audita con su usuario.
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->firstOrFail();
        $this->actingAs($admin)->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();
        $this->actingAs($secretaria)->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertForbidden();
        $this->assertSoftDeleted($matricula);
        $this->assertSame(1, AuditLog::count());

        $this->actingAs($coordinador)->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertRedirect();
        $this->assertSame($coordinador->id, $this->unicoEvento('matricula_restaurada')->user_id);
    }

    /** M19: una restauración que no puede ocupar cupo se rechaza sin escribir ni auditar. */
    #[DataProvider('condicionesQueImpidenRestaurar')]
    public function test_restaurar_rechazado_no_audita_ni_mueve_cupos(string $condicion): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaEliminada($estudiante, $curso, 'pendiente');
        match ($condicion) {
            'duplicado vigente' => $this->matriculaVigente($estudiante, $curso),
            'sin cupo' => $curso->update(['cupos_disponibles' => 0]),
            'curso archivado' => $curso->update(['estado' => 'archivado']),
            'curso eliminado' => $curso->delete(),
            'estudiante inactivo' => $estudiante->update(['estado' => 'inactivo']),
            default => null,
        };
        $antes = $this->cupos($curso);
        $auditoriasPrevias = AuditLog::count();

        $this->actingAs($admin)->patchJson(route('admin.matriculas.restaurar', $matricula->id))->assertUnprocessable();

        $this->assertSoftDeleted($matricula);
        $this->assertSame($antes, $this->cupos($curso));
        $this->assertSame($auditoriasPrevias, AuditLog::count());
        $this->assertSame(0, AuditLog::where('action', 'matricula_restaurada')->count());
    }

    /** @return array<string, array{0: string}> */
    public static function condicionesQueImpidenRestaurar(): array
    {
        return [
            'duplicado vigente' => ['duplicado vigente'],
            'sin cupo' => ['sin cupo'],
            'curso archivado' => ['curso archivado'],
            'curso eliminado' => ['curso eliminado'],
            'estudiante inactivo' => ['estudiante inactivo'],
        ];
    }

    /** M20: otro operador anuló la matrícula entre el binding y el bloqueo; el estado y el efecto salen de la fila bloqueada. */
    public function test_destroy_audita_el_estado_real_de_la_fila_bloqueada_y_no_libera_dos_veces(): void
    {
        $admin = $this->adminUser();
        [$estudiante, $curso] = $this->escenario(2);
        $matricula = $this->matriculaVigente($estudiante, $curso, 'pagada');
        $hecho = false;
        Matricula::retrieved(function () use ($matricula, $curso, &$hecho): void {
            if ($hecho) {
                return;
            }
            $hecho = true;
            DB::table('matriculas')->where('id', $matricula->id)->update(['estado' => 'anulada']);
            DB::table('cursos')->where('id', $curso->id)->increment('cupos_disponibles');
        });
        $this->actingAs($admin);

        $this->deleteJson(route('admin.matriculas.destroy', $matricula))->assertRedirect();

        $registro = $this->unicoEvento('matricula_eliminada');
        $this->assertSame('anulada', $registro->changes['estado']);
        $this->assertSame('ninguno', $registro->changes['efecto_cupo']);
        $this->assertSame($curso->cupos_maximos, $curso->fresh()->cupos_disponibles);
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: Estudiante, 1: Curso} */
    private function escenario(int $cupos): array
    {
        $estudiante = Estudiante::create([
            'dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'email' => 'ana.prueba@example.test', 'estado' => 'activo',
        ]);

        return [$estudiante, $this->curso('SOURCE', $cupos)];
    }

    private function curso(string $codigo, int $cupos): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Prueba', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Prueba',
            'cupos_maximos' => $cupos, 'cupos_disponibles' => $cupos, 'estado' => 'activo',
        ]);
    }

    /**
     * Matrícula vigente creada directamente (sin pasar por el controlador, por lo que no genera auditoría) y su cupo ocupado.
     *
     * @param  array<string, mixed>  $extra
     */
    private function matriculaVigente(Estudiante $estudiante, Curso $curso, string $estado = 'pendiente', array $extra = []): Matricula
    {
        $matricula = Matricula::create($this->datos($estudiante, $curso, $estado, $extra));
        $curso->decrement('cupos_disponibles');

        return $matricula;
    }

    /**
     * Matrícula ya eliminada lógicamente (sin pasar por el controlador): su cupo ya está liberado.
     *
     * @param  array<string, mixed>  $extra
     */
    private function matriculaEliminada(Estudiante $estudiante, Curso $curso, string $estado = 'pendiente', array $extra = []): Matricula
    {
        $matricula = Matricula::create($this->datos($estudiante, $curso, $estado, $extra));
        $matricula->delete();

        return $matricula;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datos(Estudiante $estudiante, Curso $curso, string $estado, array $extra = []): array
    {
        return $extra + [
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28',
            'estado' => $estado,
        ];
    }

    /** @return array<int, int> cupos disponibles por id de curso */
    private function cupos(Curso ...$cursos): array
    {
        $resultado = [];
        foreach ($cursos as $curso) {
            $resultado[$curso->id] = (int) Curso::withTrashed()->findOrFail($curso->id)->cupos_disponibles;
        }

        return $resultado;
    }

    private function unicoEvento(string $accion): AuditLog
    {
        return AuditLog::where('action', $accion)->sole();
    }

    private function fallarAuditoria(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
    }

    /**
     * Igualdad estricta de valores y tipos; el orden de las claves no es un contrato de la auditoría.
     *
     * @param  array<string, mixed>  $esperado
     * @param  array<string, mixed>  $real
     */
    private function assertSameSinOrden(array $esperado, array $real): void
    {
        ksort($esperado);
        ksort($real);
        $this->assertSame($esperado, $real);
    }

    private function auditoriaComoTexto(): string
    {
        return json_encode(AuditLog::all()->map->getAttributes()->all(), JSON_THROW_ON_ERROR);
    }

    private function assertNoHayDatosPrivados(): void
    {
        $texto = $this->auditoriaComoTexto();
        foreach (['PRIVATE-NOTE', '71000001', 'ana.prueba@example.test', 'Ana', 'Prueba'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    /**
     * Invariante: el efecto registrado coincide con el cambio real de «cupos_disponibles».
     *
     * @param  array<int, int>  $antes  cupos disponibles por curso antes de la operación (solo los cursos implicados)
     */
    private function assertEfecto(AuditLog $registro, array $antes): void
    {
        $deltas = [];
        foreach ($antes as $id => $valor) {
            $deltas[$id] = (int) Curso::withTrashed()->findOrFail($id)->cupos_disponibles - $valor;
        }
        $positivos = count(array_filter($deltas, static fn (int $d): bool => $d === 1));
        $negativos = count(array_filter($deltas, static fn (int $d): bool => $d === -1));
        $otros = count(array_filter($deltas, static fn (int $d): bool => ! in_array($d, [-1, 0, 1], true)));

        $real = match (true) {
            $otros > 0 => 'inconsistente',
            $positivos === 0 && $negativos === 0 => 'ninguno',
            $positivos === 0 && $negativos === 1 => 'ocupa',
            $positivos === 1 && $negativos === 0 => 'libera',
            $positivos === 1 && $negativos === 1 => 'traslada',
            default => 'inconsistente',
        };

        $this->assertSame($real, $registro->changes['efecto_cupo'], 'El efecto auditado no coincide con el cambio real de cupos.');
    }
}
