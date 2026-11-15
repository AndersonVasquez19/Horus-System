<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Matrículas con convocatoria (Ola 3, módulo 7 parte B): el cupo sale de la convocatoria y no del curso, la convocatoria
 * queda fija desde el alta y una convocatoria con matrículas vigentes no se cancela. SQLite ignora `lockForUpdate()`:
 * la carrera real se prueba en PostgreSQL (`tests/PostgresIntegrity`), sin ejecutar hasta nueva autorización.
 */
final class MatriculasConvocatoriaPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    private Estudiante $estudiante;

    private Curso $curso;

    private int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estudiante = $this->estudiante('11111111');
        $this->curso = $this->curso('CUR-1', ['cupos_maximos' => 5, 'cupos_disponibles' => 5]);
    }

    private function convocatoria(string $estado = 'abierta', ?Curso $curso = null, int $maximo = 2): Convocatoria
    {
        $convocatoria = new Convocatoria([
            'nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31', 'cupos_maximos' => $maximo,
        ]);
        $convocatoria->identificador = (string) Str::uuid();
        $convocatoria->codigo = 'CNV-2026-'.str_pad((string) ++$this->contador, 4, '0', STR_PAD_LEFT);
        $convocatoria->curso_id = ($curso ?? $this->curso)->id;
        $convocatoria->estado = $estado;
        $convocatoria->cupos_disponibles = $maximo;
        $convocatoria->revision = 1;
        $convocatoria->save();

        return $convocatoria;
    }

    /** @return array<string, mixed> */
    private function datos(?Convocatoria $convocatoria, array $cambios = []): array
    {
        return [
            'estudiante_id' => $this->estudiante->id, 'curso_id' => $this->curso->id, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'monto_pagado' => null, 'observaciones' => null,
            'convocatoria' => $convocatoria?->identificador, ...$cambios,
        ];
    }

    private function cuposCurso(): int
    {
        return (int) $this->curso->fresh()->cupos_disponibles;
    }

    private function cuposConvocatoria(Convocatoria $convocatoria): int
    {
        return (int) $convocatoria->fresh()->cupos_disponibles;
    }

    public function test_matricular_con_convocatoria_descuenta_de_ella_y_no_del_curso(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();

        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))
            ->assertCreated()
            ->assertJsonPath('data.convocatoria.identificador', $convocatoria->identificador)
            ->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo)
            ->json('data.id');

        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));
        $this->assertSame(5, $this->cuposCurso());
        $matricula = Matricula::query()->findOrFail($id);
        $this->assertSame($convocatoria->id, $matricula->convocatoria_id);
        $auditoria = AuditLog::query()->where('action', 'matricula_creada')->sole();
        $this->assertSame('ocupa', $auditoria->changes['efecto_cupo']);

        $this->como($admin, 'GET', "matriculas/{$id}")->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);
        $this->como($admin, 'GET', 'matriculas')->assertJsonPath('data.0.convocatoria.codigo', $convocatoria->codigo);
    }

    public function test_sin_convocatoria_sigue_descontando_del_curso(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();

        $this->como($admin, 'POST', 'matriculas', $this->datos(null))
            ->assertCreated()
            ->assertJsonPath('data.convocatoria', null);

        $this->assertSame(4, $this->cuposCurso());
        $this->assertSame(2, $this->cuposConvocatoria($convocatoria));
    }

    public function test_una_convocatoria_invalida_agotada_o_cerrada_se_rechaza_sin_escribir(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $otroCurso = $this->curso('CUR-2');
        $ajena = $this->convocatoria('abierta', $otroCurso);
        $borrador = $this->convocatoria('borrador');
        $cerrada = $this->convocatoria('cerrada');
        $llena = $this->convocatoria('abierta', null, 1);
        $llena->forceFill(['cupos_disponibles' => 0])->save();

        $this->como($admin, 'POST', 'matriculas', $this->datos($ajena))
            ->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no pertenece al curso seleccionado.');
        foreach ([$borrador, $cerrada] as $noMatriculable) {
            $this->como($admin, 'POST', 'matriculas', $this->datos($noMatriculable))
                ->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no admite matrículas en su estado actual.');
        }
        $this->como($admin, 'POST', 'matriculas', $this->datos($llena))
            ->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no tiene cupos disponibles.');
        $this->como($admin, 'POST', 'matriculas', $this->datos(null, ['convocatoria' => 'no-es-un-uuid']))
            ->assertUnprocessable()->assertJsonValidationErrors(['convocatoria']);
        $this->como($admin, 'POST', 'matriculas', $this->datos(null, ['convocatoria' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['convocatoria']);

        $this->assertSame(0, Matricula::query()->count());
        $this->assertSame(5, $this->cuposCurso());
    }

    public function test_la_convocatoria_cerrada_entre_la_validacion_y_el_bloqueo_se_rechaza(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();
        $hecho = false;
        Event::listen('eloquent.retrieved: '.Convocatoria::class, function () use (&$hecho, $convocatoria): void {
            if ($hecho) {
                return;
            }
            $hecho = true;
            Convocatoria::query()->whereKey($convocatoria->id)->update(['cupos_disponibles' => 0]);
        });

        $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))
            ->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no tiene cupos disponibles.');
        $this->assertSame(0, Matricula::query()->count());
    }

    public function test_anular_y_eliminar_devuelven_el_cupo_a_la_convocatoria_una_sola_vez(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));

        $this->como($admin, 'POST', "matriculas/{$id}/anular")->assertOk();
        $this->como($admin, 'POST', "matriculas/{$id}/anular")->assertOk();
        $this->assertSame(2, $this->cuposConvocatoria($convocatoria));
        $this->assertSame(5, $this->cuposCurso());

        $otro = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));
        $this->como($admin, 'DELETE', "matriculas/{$otro}")->assertOk();
        $this->assertSame(2, $this->cuposConvocatoria($convocatoria));
        $this->assertSame(5, $this->cuposCurso());
    }

    public function test_restaurar_vuelve_a_ocupar_la_convocatoria_y_respeta_su_disponibilidad(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria('abierta', null, 1);
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');
        $this->como($admin, 'DELETE', "matriculas/{$id}")->assertOk();
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));

        $otro = $this->estudiante('22222222');
        $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria, ['estudiante_id' => $otro->id]))->assertCreated();
        $this->assertSame(0, $this->cuposConvocatoria($convocatoria));

        $this->como($admin, 'POST', "matriculas/{$id}/restaurar")
            ->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no tiene cupos disponibles.');
        $this->assertTrue(Matricula::withTrashed()->findOrFail($id)->trashed());
    }

    public function test_editar_no_cambia_de_curso_ni_de_convocatoria_pero_si_el_estado(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();
        $otroCurso = $this->curso('CUR-2');
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');
        $editar = fn (array $cambios) => $this->como($admin, 'PUT', "matriculas/{$id}", [
            'estudiante_id' => $this->estudiante->id, 'curso_id' => $this->curso->id, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pagada', 'monto_pagado' => null, 'observaciones' => null, ...$cambios,
        ]);

        $editar(['curso_id' => $otroCurso->id])
            ->assertUnprocessable()->assertJsonPath('errors.curso_id.0', 'La matrícula pertenece a una convocatoria: no se puede cambiar de curso.');
        $editar(['convocatoria' => $this->convocatoria()->identificador])
            ->assertUnprocessable()->assertJsonValidationErrors(['convocatoria']);
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));

        $editar([])->assertOk()->assertJsonPath('data.estado', 'pagada')->assertJsonPath('data.convocatoria.codigo', $convocatoria->codigo);
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));

        // Anular por PUT libera el cupo de la convocatoria; reactivarla lo vuelve a ocupar.
        $editar(['estado' => 'anulada'])->assertOk();
        $this->assertSame(2, $this->cuposConvocatoria($convocatoria));
        $editar(['estado' => 'pendiente'])->assertOk();
        $this->assertSame(1, $this->cuposConvocatoria($convocatoria));
        $this->assertSame(5, $this->cuposCurso());
    }

    public function test_reactivar_en_una_convocatoria_cerrada_se_rechaza(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');
        $this->como($admin, 'POST', "matriculas/{$id}/anular")->assertOk();
        $convocatoria->forceFill(['estado' => 'cerrada'])->save();

        $this->como($admin, 'PUT', "matriculas/{$id}", [
            'estudiante_id' => $this->estudiante->id, 'curso_id' => $this->curso->id, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'monto_pagado' => null, 'observaciones' => null,
        ])->assertUnprocessable()->assertJsonPath('errors.convocatoria.0', 'La convocatoria no admite matrículas en su estado actual.');
        $this->assertSame(2, $this->cuposConvocatoria($convocatoria));
    }

    public function test_una_convocatoria_con_matriculas_vigentes_no_se_cancela(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $convocatoria = $this->convocatoria();
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos($convocatoria))->assertCreated()->json('data.id');

        $cancelar = fn () => $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json('POST', "/api/panel/v1/academico/convocatorias/{$convocatoria->identificador}/estado", [
                'estado' => 'cancelada', '_revision' => $convocatoria->fresh()->etag(),
            ]);

        $cancelar()->assertStatus(409);
        $this->assertSame('abierta', $convocatoria->fresh()->estado);

        $this->como($admin, 'POST', "matriculas/{$id}/anular")->assertOk();
        $cancelar()->assertOk();
        $this->assertSame('cancelada', $convocatoria->fresh()->estado);
    }
}
