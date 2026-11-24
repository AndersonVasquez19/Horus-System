<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\LlamaCursosDelPanel;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Fase 1 de la auditoría del panel (#12, parte de longitudes): `observaciones` de matrícula y `descripcion`
 * de curso tienen tope en creación y edición. SQLite en memoria.
 */
class LimitesTextoLargoTest extends TestCase
{
    use LlamaCursosDelPanel;
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_la_matricula_rechaza_observaciones_demasiado_largas(): void
    {
        $admin = $this->adminUser();
        $curso = Curso::create([
            'codigo' => 'COD-LIM-1', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia',
            'duracion_horas' => 10, 'precio' => 10, 'modalidad' => 'virtual', 'horario' => 'x',
            'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => '70005555', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']);

        $base = [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => \App\Support\HoraLocal::hoy(),
            'estado' => 'pendiente',
        ];

        $this->actingAs($admin)->panelMatriculas('POST', '', $base + ['observaciones' => str_repeat('a', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors('observaciones');
        $this->assertDatabaseCount('matriculas', 0);

        $this->actingAs($admin)->panelMatriculas('POST', '', $base + ['observaciones' => str_repeat('a', 2000)]);
        $this->assertDatabaseCount('matriculas', 1);
    }

    public function test_el_curso_rechaza_descripciones_demasiado_largas_al_crear(): void
    {
        $admin = $this->adminUser();
        $datos = [
            'codigo' => 'COD-LIM-2', 'nombre' => 'Curso', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
            'precio' => 10, 'modalidad' => 'virtual', 'horario' => 'x', 'cupos_maximos' => 5, 'cupos_disponibles' => 5,
            'estado' => 'activo',
        ];

        $this->actingAs($admin)->panelCursos('POST', '', $datos + ['descripcion' => str_repeat('a', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('descripcion');
        $this->assertDatabaseMissing('cursos', ['codigo' => 'COD-LIM-2']);
    }
}
