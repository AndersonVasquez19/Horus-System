<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminMatriculaContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_course_details_link_to_a_preselected_enrollment_form(): void
    {
        [$estudiante, $curso] = $this->records();
        $this->actingAs($this->adminUser());

        $this->get(route('admin.estudiantes.show', $estudiante))
            ->assertOk()
            ->assertSee('href="'.route('admin.matriculas.create', ['estudiante_id' => $estudiante->id]).'"', false);
        $this->get(route('admin.cursos.show', $curso))
            ->assertOk()
            ->assertSee('href="'.route('admin.matriculas.create', ['curso_id' => $curso->id]).'"', false);

        $this->get(route('admin.matriculas.create', [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        ]))->assertOk()
            ->assertSee('<option value="'.$estudiante->id.'" selected>', false)
            ->assertSee('<option value="'.$curso->id.'" selected>', false);

        $this->assertDatabaseCount('matriculas', 0);
        $this->assertSame(2, $curso->fresh()->cupos_disponibles);

        $this->post(route('admin.matriculas.store'), [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ])->assertRedirect(route('admin.matriculas.show', Matricula::sole()));

        $this->assertDatabaseCount('matriculas', 1);
        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
    }

    public function test_read_only_staff_do_not_see_enrollment_actions_and_cannot_open_the_form(): void
    {
        [$estudiante, $curso] = $this->records();
        $this->adminUser();
        $lector = User::factory()->create();
        $lector->assignRole('coordinador');
        $lector->roles()->firstOrFail()->syncPermissions(['estudiantes.ver', 'cursos.ver']);
        $this->actingAs($lector);

        $this->get(route('admin.estudiantes.show', $estudiante))->assertOk()->assertDontSee('Nueva matrícula');
        $this->get(route('admin.cursos.show', $curso))->assertOk()->assertDontSee('Nueva matrícula');
        $this->get(route('admin.matriculas.create', ['estudiante_id' => $estudiante->id]))->assertForbidden();
    }

    public function test_context_preselects_both_fields_without_writing(): void
    {
        [$estudiante, $curso] = $this->records();
        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.create', ['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id]))
            ->assertOk();
        $this->assertMatchesRegularExpression('/<select id="estudiante_id"[^>]*>\s*<option[^>]*>[^<]*<\/option>\s*<option value="'.$estudiante->id.'" selected>/', $response->getContent());
        $this->assertMatchesRegularExpression('/<select id="curso_id"[^>]*>\s*<option[^>]*>[^<]*<\/option>\s*<option value="'.$curso->id.'" selected>/', $response->getContent());
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertSame(2, $curso->fresh()->cupos_disponibles);
    }

    public function test_inactive_students_and_unavailable_courses_have_no_enrollment_action(): void
    {
        [$estudiante, $curso] = $this->records();
        $this->actingAs($this->adminUser());
        $estudiante->update(['estado' => 'inactivo']);
        $curso->update(['cupos_disponibles' => 0]);

        $this->get(route('admin.estudiantes.show', $estudiante))->assertOk()->assertDontSee('Nueva matrícula');
        $this->get(route('admin.cursos.show', $curso))->assertOk()->assertDontSee('Nueva matrícula');

        $curso->update(['cupos_disponibles' => 2, 'estado' => 'archivado']);
        $this->get(route('admin.cursos.show', $curso))->assertOk()->assertDontSee('Nueva matrícula');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidContext(): iterable
    {
        yield 'array' => [[1]];
        yield 'missing' => ['999999'];
        yield 'text' => ['invalido'];
    }

    #[DataProvider('invalidContext')]
    public function test_invalid_context_does_not_select_another_record(mixed $id): void
    {
        [$estudiante, $curso] = $this->records();
        $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.create', ['estudiante_id' => $id, 'curso_id' => $id]))
            ->assertOk()
            ->assertDontSee('<option value="'.$estudiante->id.'" selected>', false)
            ->assertDontSee('<option value="'.$curso->id.'" selected>', false);
        $this->assertDatabaseCount('matriculas', 0);
    }

    public function test_unavailable_context_is_not_added_to_the_selectors_or_accepted_on_submit(): void
    {
        [$estudiante, $curso] = $this->records();
        $estudiante->delete();
        $curso->delete();
        $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.create', ['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id]))
            ->assertOk()
            ->assertDontSee($estudiante->dni)
            ->assertDontSee($curso->codigo);
        $this->post(route('admin.matriculas.store'), [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ])->assertSessionHasErrors(['estudiante_id', 'curso_id']);
        $this->assertDatabaseCount('matriculas', 0);
    }

    public function test_validation_input_takes_precedence_over_the_original_context(): void
    {
        [$original, $curso] = $this->records();
        $otro = Estudiante::create(['dni' => '70123457', 'nombres' => 'Otra', 'apellidos' => 'Persona', 'estado' => 'activo']);
        $url = route('admin.matriculas.create', ['estudiante_id' => $original->id, 'curso_id' => $curso->id]);

        $this->actingAs($this->adminUser())->from($url)->post(route('admin.matriculas.store'), [
            'estudiante_id' => $otro->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'invalido', 'observaciones' => 'Conservar borrador',
        ])->assertRedirect($url)->assertSessionHasErrors('estado');

        $this->get($url)->assertOk()
            ->assertSee('<option value="'.$otro->id.'" selected>', false)
            ->assertSee('Conservar borrador');
        $this->assertDatabaseCount('matriculas', 0);
    }

    /** @return array{Estudiante, Curso} */
    private function records(): array
    {
        return [
            Estudiante::create(['dni' => '70123456', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']),
            Curso::create([
                'codigo' => 'CTX-001', 'nombre' => 'Curso contextual', 'descripcion' => 'Curso ficticio',
                'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150,
                'modalidad' => 'virtual', 'horario' => 'Lunes 18:00',
                'cupos_maximos' => 2, 'cupos_disponibles' => 2, 'estado' => 'activo',
            ]),
        ];
    }
}
