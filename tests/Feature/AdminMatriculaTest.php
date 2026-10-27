<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMatriculaTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_decrements_course_seats_and_rejects_active_duplicates(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 2, 'cupos_disponibles' => 2]);
        $estudiante = $this->makeStudent('70123456');

        $this->actingAs($this->adminUser())
            ->post(route('admin.matriculas.store'), $this->matriculaData($estudiante, $curso))
            ->assertRedirect(route('admin.matriculas.show', Matricula::firstOrFail()))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cursos', [
            'id' => $curso->id,
            'cupos_disponibles' => 1,
        ]);

        $this->post(route('admin.matriculas.store'), $this->matriculaData($estudiante, $curso))
            ->assertSessionHasErrors([
                'estudiante_id' => 'El estudiante ya está matriculado en este curso.',
            ]);

        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
        $this->assertSame(1, Matricula::count());
    }

    public function test_store_rejects_courses_without_available_seats(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 1, 'cupos_disponibles' => 0]);
        $estudiante = $this->makeStudent('70123456');

        $this->actingAs($this->adminUser())
            ->post(route('admin.matriculas.store'), $this->matriculaData($estudiante, $curso))
            ->assertSessionHasErrors([
                'curso_id' => 'El curso no tiene cupos disponibles.',
            ]);

        $this->assertSame(0, Matricula::count());
    }

    public function test_create_and_edit_forms_render(): void
    {
        $curso = $this->makeCourse();
        $estudiante = $this->makeStudent('70123456');
        $matricula = $this->makeEnrollment($estudiante, $curso);

        $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.create'))
            ->assertOk()
            ->assertSee('Nueva matrícula');

        $this->get(route('admin.matriculas.edit', $matricula))
            ->assertOk()
            ->assertSee('Editar matrícula')
            ->assertSee('Curso de prueba');
    }

    public function test_update_transfers_and_releases_seats_when_course_or_status_changes(): void
    {
        $cursoOriginal = $this->makeCourse(['codigo' => 'CUR-001', 'cupos_maximos' => 2, 'cupos_disponibles' => 2]);
        $cursoNuevo = $this->makeCourse(['codigo' => 'CUR-002', 'cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $estudiante = $this->makeStudent('70123456');
        $matricula = $this->makeEnrollment($estudiante, $cursoOriginal);

        $this->actingAs($this->adminUser())
            ->put(route('admin.matriculas.update', $matricula), $this->matriculaData($estudiante, $cursoOriginal, [
                'estado' => 'pagada',
                'monto_pagado' => 150,
            ]))
            ->assertRedirect(route('admin.matriculas.show', $matricula));

        $this->assertSame(1, $cursoOriginal->fresh()->cupos_disponibles);

        $this->put(route('admin.matriculas.update', $matricula), $this->matriculaData($estudiante, $cursoNuevo, [
            'estado' => 'pagada',
            'monto_pagado' => 150,
        ]))
            ->assertRedirect(route('admin.matriculas.show', $matricula));

        $this->assertSame(2, $cursoOriginal->fresh()->cupos_disponibles);
        $this->assertSame(0, $cursoNuevo->fresh()->cupos_disponibles);

        $this->put(route('admin.matriculas.update', $matricula), $this->matriculaData($estudiante, $cursoNuevo, [
            'estado' => 'anulada',
        ]))
            ->assertRedirect(route('admin.matriculas.show', $matricula));

        $this->assertSame(1, $cursoNuevo->fresh()->cupos_disponibles);

        $this->put(route('admin.matriculas.update', $matricula), $this->matriculaData($estudiante, $cursoNuevo))
            ->assertRedirect(route('admin.matriculas.show', $matricula));

        $this->assertSame(0, $cursoNuevo->fresh()->cupos_disponibles);
    }

    public function test_anular_releases_a_seat_only_once(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $estudiante = $this->makeStudent('70123456');
        $matricula = $this->makeEnrollment($estudiante, $curso);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.matriculas.anular', $matricula))
            ->assertRedirect(route('admin.matriculas.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
        $this->assertSame('anulada', $matricula->fresh()->estado);

        $this->patch(route('admin.matriculas.anular', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));

        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
    }

    public function test_soft_delete_releases_and_restore_reacquires_a_seat(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $estudiante = $this->makeStudent('70123456');
        $matricula = $this->makeEnrollment($estudiante, $curso);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.matriculas.destroy', $matricula))
            ->assertRedirect(route('admin.matriculas.index'));

        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
        $this->assertSoftDeleted('matriculas', ['id' => $matricula->id]);

        $this->patch(route('admin.matriculas.restaurar', $matricula->id))
            ->assertRedirect(route('admin.matriculas.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
        $this->assertNotSoftDeleted('matriculas', ['id' => $matricula->id]);
    }

    public function test_restore_rejects_when_the_course_has_no_available_seats(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 2, 'cupos_disponibles' => 2]);
        $estudianteEliminado = $this->makeStudent('70123456');
        $matriculaEliminada = $this->makeEnrollment($estudianteEliminado, $curso);
        $this->actingAs($this->adminUser())
            ->delete(route('admin.matriculas.destroy', $matriculaEliminada));
        $this->makeEnrollment($this->makeStudent('70123457'), $curso);
        $this->makeEnrollment($this->makeStudent('70123458'), $curso);

        $this->patch(route('admin.matriculas.restaurar', $matriculaEliminada->id))
            ->assertSessionHasErrors([
                'curso_id' => 'El curso no tiene cupos disponibles para restaurar esta matrícula.',
            ]);

        $this->assertSoftDeleted('matriculas', ['id' => $matriculaEliminada->id]);
        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
    }

    public function test_csv_export_applies_state_and_course_filters_and_has_utf8_bom(): void
    {
        $curso = $this->makeCourse(['codigo' => 'CUR-001']);
        $otroCurso = $this->makeCourse(['codigo' => 'CUR-002']);
        $estudianteUno = $this->makeStudent('70123456');
        $estudianteDos = $this->makeStudent('70123457');
        $estudianteTres = $this->makeStudent('70123458');
        $pagada = $this->makeEnrollment($estudianteUno, $curso, ['estado' => 'pagada', 'monto_pagado' => 150]);
        $this->makeEnrollment($estudianteDos, $curso, ['estado' => 'pendiente']);
        $this->makeEnrollment($estudianteTres, $otroCurso, ['estado' => 'pagada', 'monto_pagado' => 150]);

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.csv', ['estado' => 'pagada', 'curso_id' => $curso->id]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertMatchesRegularExpression(
            '/filename=matriculas_\d{8}_\d{6}\.csv/',
            $response->headers->get('content-disposition'),
        );

        $contenido = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString('ID,DNI,Estudiante,Curso,"Código Curso",Fecha,Estado,Monto', $contenido);
        $this->assertStringContainsString((string) $pagada->id, $contenido);
        $this->assertStringContainsString('CUR-001', $contenido);
        $this->assertStringNotContainsString('CUR-002', $contenido);
    }

    public function test_matricula_list_filters_by_state_and_course(): void
    {
        $curso = $this->makeCourse(['codigo' => 'CUR-001', 'nombre' => 'Curso principal']);
        $otroCurso = $this->makeCourse(['codigo' => 'CUR-002', 'nombre' => 'Curso alternativo']);
        $estudianteUno = $this->makeStudent('70123456');
        $estudianteDos = $this->makeStudent('70123457');
        $this->makeEnrollment($estudianteUno, $curso, ['estado' => 'pagada']);
        $this->makeEnrollment($estudianteDos, $otroCurso, ['estado' => 'pendiente']);

        $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.index', ['estado' => 'pagada', 'curso_id' => $curso->id]))
            ->assertOk()
            ->assertViewHas(
                'matriculas',
                fn ($matriculas) => $matriculas->total() === 1
                    && $matriculas->first()->curso_id === $curso->id,
            );
    }

    private function makeCourse(array $attributes = []): Curso
    {
        return Curso::create(array_replace([
            'codigo' => 'CUR-TEST',
            'nombre' => 'Curso de prueba',
            'descripcion' => 'Descripción del curso.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 5,
            'cupos_disponibles' => 5,
            'estado' => 'activo',
        ], $attributes));
    }

    private function makeStudent(string $dni): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni,
            'nombres' => 'Estudiante',
            'apellidos' => 'De Prueba',
            'estado' => 'activo',
        ]);
    }

    private function makeEnrollment(Estudiante $estudiante, Curso $curso, array $attributes = []): Matricula
    {
        $matricula = Matricula::create(array_replace([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
            'monto_pagado' => null,
        ], $attributes));

        if ($matricula->estado !== 'anulada') {
            $curso->decrement('cupos_disponibles');
        }

        return $matricula;
    }

    private function matriculaData(Estudiante $estudiante, Curso $curso, array $attributes = []): array
    {
        return array_replace([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => today()->toDateString(),
            'estado' => 'pendiente',
            'monto_pagado' => null,
            'observaciones' => null,
        ], $attributes);
    }
}
