<?php

namespace Tests\Feature;

use App\Models\Estudiante;
use App\Models\Curso;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEstudianteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_a_student(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.estudiantes.store'), [
                'dni' => '70123456',
                'nombres' => 'Lucía',
                'apellidos' => 'Pérez Soto',
                'email' => 'lucia@example.com',
                'telefono' => '987654321',
                'fecha_nacimiento' => '2000-04-15',
                'direccion' => 'Jr. Lima 123, Cajamarca',
                'estado' => 'activo',
            ])
            ->assertRedirect(route('admin.estudiantes.index'))
            ->assertSessionHas('success');

        $estudiante = Estudiante::where('dni', '70123456')->firstOrFail();

        $this->put(route('admin.estudiantes.update', $estudiante), [
            'dni' => '70123456',
            'nombres' => 'Lucía María',
            'apellidos' => 'Pérez Soto',
            'email' => 'lucia@example.com',
            'telefono' => '987654321',
            'fecha_nacimiento' => '2000-04-15',
            'direccion' => 'Jr. Lima 123, Cajamarca',
            'estado' => 'inactivo',
        ])
            ->assertRedirect(route('admin.estudiantes.show', $estudiante))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('estudiantes', [
            'id' => $estudiante->id,
            'nombres' => 'Lucía María',
            'estado' => 'inactivo',
        ]);
    }

    public function test_student_dni_is_validated_as_unique_and_eight_digits(): void
    {
        $this->makeStudent('70123456');

        $this->actingAs($this->adminUser())
            ->post(route('admin.estudiantes.store'), [
                'dni' => '70123456',
                'nombres' => 'Ana',
                'apellidos' => 'Rojas',
                'estado' => 'activo',
            ])
            ->assertSessionHasErrors(['dni']);

        $this->post(route('admin.estudiantes.store'), [
            'dni' => '123',
            'nombres' => 'Ana',
            'apellidos' => 'Rojas',
            'estado' => 'activo',
        ])
            ->assertSessionHasErrors(['dni']);
    }

    public function test_student_list_searches_and_restores_soft_deleted_records(): void
    {
        $vigente = $this->makeStudent('70123456', ['nombres' => 'María Fernanda']);
        $eliminado = $this->makeStudent('70123457', ['nombres' => 'Carlos']);
        $eliminado->delete();

        $this->actingAs($this->adminUser())
            ->get(route('admin.estudiantes.index', ['busqueda' => 'María']))
            ->assertOk()
            ->assertSee($vigente->dni)
            ->assertDontSee($eliminado->dni);

        $this->get(route('admin.estudiantes.index', ['eliminados' => 1]))
            ->assertOk()
            ->assertSee($eliminado->dni)
            ->assertSee('Restaurar estudiante');

        $this->patch(route('admin.estudiantes.restaurar', $eliminado->id))
            ->assertRedirect(route('admin.estudiantes.index', ['eliminados' => 1]))
            ->assertSessionHas('success');

        $this->assertNotSoftDeleted('estudiantes', ['id' => $eliminado->id]);
    }

    public function test_student_delete_uses_soft_deletes(): void
    {
        $estudiante = $this->makeStudent('70123456');

        $this->actingAs($this->adminUser())
            ->delete(route('admin.estudiantes.destroy', $estudiante))
            ->assertRedirect(route('admin.estudiantes.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('estudiantes', ['id' => $estudiante->id]);
    }

    public function test_student_detail_displays_its_enrollments(): void
    {
        $estudiante = $this->makeStudent('70123456', ['nombres' => 'María']);
        $curso = Curso::create([
            'codigo' => 'CUR-DET',
            'nombre' => 'Curso de detalle',
            'descripcion' => 'Descripción de prueba.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Martes, 18:00-20:00',
            'cupos_maximos' => 10,
            'cupos_disponibles' => 9,
            'estado' => 'activo',
        ]);
        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada',
            'monto_pagado' => 150,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.estudiantes.show', $estudiante))
            ->assertOk()
            ->assertSee('Información personal')
            ->assertSee('Matrículas del estudiante')
            ->assertSee('Curso de detalle')
            ->assertSee('S/ 150.00');
    }

    public function test_student_list_is_paginated_by_fifteen(): void
    {
        for ($index = 1; $index <= 16; $index++) {
            $this->makeStudent(str_pad((string) (71000000 + $index), 8, '0', STR_PAD_LEFT));
        }

        $this->actingAs($this->adminUser())
            ->get(route('admin.estudiantes.index'))
            ->assertOk()
            ->assertViewHas('estudiantes', fn ($estudiantes) => $estudiantes->count() === 15 && $estudiantes->total() === 16);
    }

    private function makeStudent(string $dni, array $attributes = []): Estudiante
    {
        return Estudiante::create(array_replace([
            'dni' => $dni,
            'nombres' => 'Estudiante',
            'apellidos' => 'De Prueba',
            'estado' => 'activo',
        ], $attributes));
    }
}
