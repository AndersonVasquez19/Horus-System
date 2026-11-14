<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Matricula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\Support\LlamaCursosDelPanel;
use Tests\Support\LlamaEstudiantesDelPanel;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Reglas de matrícula vigentes, comprobadas en PostgreSQL real (secuenciales, una sola sesión).
 * PREPARADAS, NO EJECUTADAS. Datos ficticios.
 *
 * La regla de rematrícula que fijan estas pruebas es la del código actual: se admite una nueva
 * matrícula si todas las anteriores del estudiante en el curso están anuladas o eliminadas
 * lógicamente. Su confirmación como regla de negocio está pendiente.
 */
class MatriculaRulesPostgresTest extends TestCase
{
    use LlamaCursosDelPanel;
    use LlamaEstudiantesDelPanel;
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;
    use RequiresIsolatedPostgres;

    public function test_a_second_active_enrollment_for_the_same_student_and_course_is_rejected(): void
    {
        $estudiante = $this->makeStudent('30000001');
        $curso = $this->makeCourse('PG-DUP', 5);
        $admin = $this->adminUser();

        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso))
            ->assertCreated();
        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso))
            ->assertUnprocessable()->assertJsonValidationErrors('estudiante_id');

        $this->assertSame(1, $this->activeEnrollments($estudiante, $curso));
        $this->assertSame(4, $curso->fresh()->cupos_disponibles);
    }

    public function test_after_annulment_a_new_enrollment_is_allowed_and_the_seat_is_reused(): void
    {
        $estudiante = $this->makeStudent('30000002');
        $curso = $this->makeCourse('PG-ANU', 1);
        $admin = $this->adminUser();

        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));
        $primera = Matricula::query()->sole();
        $this->panelMatriculas('POST', $primera->id.'/anular')->assertOk();
        $this->assertSame(1, $curso->fresh()->cupos_disponibles);

        $this->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));

        $this->assertSame(1, $this->activeEnrollments($estudiante, $curso));
        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
    }

    public function test_after_soft_delete_a_new_enrollment_is_allowed(): void
    {
        $estudiante = $this->makeStudent('30000003');
        $curso = $this->makeCourse('PG-DEL', 2);
        $admin = $this->adminUser();

        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));
        $this->panelMatriculas('DELETE', (string) Matricula::query()->sole())->assertOk();

        $this->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));

        $this->assertSame(1, $this->activeEnrollments($estudiante, $curso));
        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
    }

    public function test_restoring_is_rejected_while_another_active_enrollment_exists(): void
    {
        $estudiante = $this->makeStudent('30000004');
        $curso = $this->makeCourse('PG-RES', 3);
        $admin = $this->adminUser();

        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));
        $eliminada = Matricula::query()->sole();
        $this->panelMatriculas('DELETE', (string) $eliminada->id);
        $this->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));

        $this->panelMatriculas('POST', $eliminada->id.'/restaurar')
            ->assertUnprocessable()->assertJsonValidationErrors('estudiante_id');

        $this->assertSoftDeleted($eliminada);
        $this->assertSame(1, $this->activeEnrollments($estudiante, $curso));
        $this->assertSame(2, $curso->fresh()->cupos_disponibles);
    }

    public function test_seats_never_go_negative_through_the_panel(): void
    {
        $curso = $this->makeCourse('PG-CUP', 1);
        $admin = $this->adminUser();

        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($this->makeStudent('30000005'), $curso));
        $this->panelMatriculas('POST', '', $this->enrollmentPayload($this->makeStudent('30000006'), $curso))
            ->assertUnprocessable()->assertJsonValidationErrors('curso_id');

        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
    }

    public function test_searches_are_case_insensitive_on_postgresql(): void
    {
        $curso = $this->makeCourse('PG-BUS', 5, 'Redes y Servidores');
        $estudiante = $this->makeStudent('30000007', 'Juan', 'Pérez');
        $admin = $this->adminUser();
        $this->actingAs($admin)->panelMatriculas('POST', '', $this->enrollmentPayload($estudiante, $curso));

        // PostgreSQL distingue mayúsculas con LIKE; whereLike() usa ILIKE.
        // El listado de estudiantes lo sirve la API del panel (Blade retirado el 14/11/2026): ILIKE sobre `apellidos`.
        $this->assertContains('Pérez', $this->panelEstudiantes('GET', '', ['busqueda' => 'JUAN'])->assertOk()->json('data.*.apellidos'));
        $this->assertContains('Redes y Servidores', $this->panelCursos('GET', '', ['busqueda' => 'redes'])->assertOk()->json('data.*.nombre'));
        $this->panelMatriculas('GET', '', ['busqueda' => 'juan'])->assertOk()->assertJsonPath('meta.total', 1);

        // Búsqueda global: coincidencia parcial por ID ("id"::text ilike) y por curso sin mayúsculas.
        $matricula = Matricula::query()->sole();
        $porId = $this->get(route('admin.busqueda.index', ['q' => (string) $matricula->id]))->assertOk();
        $this->assertContains($matricula->id, $porId->viewData('resultados')['matriculas']->pluck('id')->all());
        $porCurso = $this->get(route('admin.busqueda.index', ['q' => 'SERVIDORES']))->assertOk();
        $this->assertSame([$matricula->id], $porCurso->viewData('resultados')['matriculas']->pluck('id')->all());
    }
}
