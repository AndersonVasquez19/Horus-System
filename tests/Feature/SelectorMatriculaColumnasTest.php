<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auditoría del panel (Fase 2, #13): los selectores de crear/editar matrícula solo hidratan las columnas que
 * pintan. Lo visible no cambia. SQLite en memoria.
 */
class SelectorMatriculaColumnasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_el_formulario_de_creacion_muestra_lo_mismo_sin_hidratar_datos_de_contacto(): void
    {
        Estudiante::create([
            'dni' => '70007777', 'nombres' => 'Lucia', 'apellidos' => 'Selector', 'estado' => 'activo',
            'email' => 'lucia.selector@example.test',
        ]);
        Curso::create([
            'codigo' => 'COD-SEL-1', 'nombre' => 'Curso Selector', 'descripcion' => 'Descripcion privada del curso',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 10, 'modalidad' => 'virtual',
            'horario' => 'x', 'cupos_maximos' => 5, 'cupos_disponibles' => 3, 'estado' => 'activo',
        ]);

        $respuesta = $this->actingAs($this->adminUser())->get(route('admin.matriculas.create'))->assertOk();

        $respuesta->assertSee('70007777 · Lucia Selector', false)
            ->assertSee('COD-SEL-1 · Curso Selector (3 cupos)', false)
            ->assertDontSee('lucia.selector@example.test')
            ->assertDontSee('Descripcion privada del curso');

        $respuesta->assertViewHas('estudiantes', fn ($lista): bool => ! array_key_exists('email', $lista->first()->getAttributes()));
        $respuesta->assertViewHas('cursos', fn ($lista): bool => ! array_key_exists('descripcion', $lista->first()->getAttributes()));
    }
}
