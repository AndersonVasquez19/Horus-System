<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCursoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_a_course(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.cursos.store'), $this->courseData())
            ->assertRedirect(route('admin.cursos.show', Curso::firstOrFail()))
            ->assertSessionHas('success');

        $curso = Curso::firstOrFail();
        $this->assertSame(20, $curso->cupos_disponibles);

        $this->put(route('admin.cursos.update', $curso), $this->courseData([
            'nombre' => 'Curso actualizado',
            'cupos_maximos' => 25,
        ]))
            ->assertRedirect(route('admin.cursos.show', $curso))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cursos', [
            'id' => $curso->id,
            'nombre' => 'Curso actualizado',
            'cupos_maximos' => 25,
            'cupos_disponibles' => 25,
        ]);
    }

    public function test_course_code_price_and_minimum_seats_are_validated(): void
    {
        $this->makeCourse(['codigo' => 'CUR-001']);

        $this->actingAs($this->adminUser())
            ->post(route('admin.cursos.store'), $this->courseData([
                'codigo' => 'CUR-001',
                'precio' => 'texto',
                'cupos_maximos' => 0,
            ]))
            ->assertSessionHasErrors(['codigo', 'precio', 'cupos_maximos']);
    }

    public function test_course_create_and_edit_forms_render(): void
    {
        $curso = $this->makeCourse();

        $this->actingAs($this->adminUser())
            ->get(route('admin.cursos.create'))
            ->assertOk()
            ->assertSee('Nuevo curso');

        $this->get(route('admin.cursos.edit', $curso))
            ->assertOk()
            ->assertSee('Editar curso')
            ->assertSee('Curso de prueba')
            ->assertSee('Matrículas activas actuales: 0');
    }

    public function test_course_capacity_cannot_be_reduced_below_active_enrollments(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 2, 'cupos_disponibles' => 0]);
        $this->makeEnrollment($curso, '70123456');
        $this->makeEnrollment($curso, '70123457');

        $this->actingAs($this->adminUser())
            ->put(route('admin.cursos.update', $curso), $this->courseData([
                'codigo' => $curso->codigo,
                'cupos_maximos' => 1,
            ]))
            ->assertSessionHasErrors([
                'cupos_maximos' => 'No puedes reducir los cupos por debajo de las 2 matrículas activas.',
            ]);

        $this->assertDatabaseHas('cursos', [
            'id' => $curso->id,
            'cupos_maximos' => 2,
            'cupos_disponibles' => 0,
        ]);
    }

    public function test_course_capacity_is_recalculated_after_update(): void
    {
        $curso = $this->makeCourse(['cupos_maximos' => 3, 'cupos_disponibles' => 2]);
        $this->makeEnrollment($curso, '70123456');

        $this->actingAs($this->adminUser())
            ->put(route('admin.cursos.update', $curso), $this->courseData([
                'codigo' => $curso->codigo,
                'cupos_maximos' => 5,
            ]))
            ->assertRedirect(route('admin.cursos.show', $curso));

        $this->assertDatabaseHas('cursos', [
            'id' => $curso->id,
            'cupos_maximos' => 5,
            'cupos_disponibles' => 4,
        ]);
    }

    public function test_course_list_filters_and_restores_soft_deleted_records(): void
    {
        $curso = $this->makeCourse([
            'codigo' => 'CUR-TEC',
            'nombre' => 'Tecnología aplicada',
            'categoria' => 'tecnologia',
        ]);
        $deleted = $this->makeCourse([
            'codigo' => 'CUR-DEL',
            'nombre' => 'Curso eliminado',
        ]);
        $deleted->delete();

        $this->actingAs($this->adminUser())
            ->get(route('admin.cursos.index', ['categoria' => 'tecnologia', 'estado' => 'activo']))
            ->assertOk()
            ->assertSee('CUR-TEC')
            ->assertDontSee('CUR-DEL');

        $this->get(route('admin.cursos.index', ['eliminados' => 1]))
            ->assertOk()
            ->assertSee('CUR-DEL')
            ->assertSee('Restaurar curso');

        $this->patch(route('admin.cursos.restaurar', $deleted->id))
            ->assertRedirect(route('admin.cursos.index', ['eliminados' => 1]))
            ->assertSessionHas('success');

        $this->assertNotSoftDeleted('cursos', ['id' => $deleted->id]);
    }

    public function test_course_detail_displays_enrolled_students(): void
    {
        $curso = $this->makeCourse();
        $estudiante = $this->makeEnrollment($curso, '70123456');
        $curso->update(['cupos_disponibles' => 19]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.cursos.show', $curso))
            ->assertOk()
            ->assertSee('Estudiantes matriculados')
            ->assertSee($estudiante->dni)
            ->assertSee($estudiante->nombres)
            ->assertSee('19 / 20');
    }

    private function makeCourse(array $attributes = []): Curso
    {
        $data = array_replace($this->courseData(), $attributes);
        $data['cupos_disponibles'] = $attributes['cupos_disponibles'] ?? $data['cupos_maximos'];

        return Curso::create($data);
    }

    private function courseData(array $attributes = []): array
    {
        return array_replace([
            'codigo' => 'CUR-TEST',
            'nombre' => 'Curso de prueba',
            'descripcion' => 'Descripción del curso de prueba.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 20,
            'estado' => 'activo',
        ], $attributes);
    }

    private function makeEnrollment(Curso $curso, string $dni): Estudiante
    {
        $estudiante = Estudiante::create([
            'dni' => $dni,
            'nombres' => 'Estudiante',
            'apellidos' => 'Matriculado',
            'estado' => 'activo',
        ]);

        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada',
            'monto_pagado' => $curso->precio,
        ]);

        return $estudiante;
    }
}
