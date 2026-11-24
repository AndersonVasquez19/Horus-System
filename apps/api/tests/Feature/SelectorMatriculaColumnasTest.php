<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Auditoría del panel (Fase 2, #13): los selectores de crear/editar matrícula solo exponen lo que pintan. Desde
 * que buscan en el servidor, lo comprueban las opciones JSON y el HTML del formulario. SQLite en memoria.
 */
class SelectorMatriculaColumnasTest extends TestCase
{
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_el_listado_y_las_opciones_no_exponen_datos_de_contacto(): void
    {
        Estudiante::create([
            'dni' => '70007777', 'nombres' => 'Lucia', 'apellidos' => 'Selector', 'estado' => 'activo',
            'email' => 'lucia.selector@example.test',
        ]);
        Curso::create([
            'codigo' => 'COD-SEL-1', 'nombre' => 'Curso Selector', 'descripcion' => 'Descripcion privada del curso',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 10, 'modalidad' => 'virtual',
            'horario' => 'x', 'cupos_disponibles' => 3, 'cupos_maximos' => 5, 'estado' => 'activo',
        ]);
        $admin = $this->adminUser();

        // El listado de matrículas no lleva las listas de estudiantes ni de cursos: solo las opciones, y mínimas.
        $this->actingAs($admin)->panelMatriculas('GET', '')
            ->assertOk()
            ->assertDontSee('70007777')
            ->assertDontSee('lucia.selector@example.test')
            ->assertDontSee('Descripcion privada del curso');

        $estudiantes = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertOk();
        $estudiantes->assertExactJson(['data' => [
            ['id' => Estudiante::query()->value('id'), 'texto' => '70007777 · Lucia Selector', 'eliminado' => false],
        ]]);
        $this->assertStringNotContainsString('lucia.selector@example.test', $estudiantes->getContent());

        $cursos = $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'crear'])->assertOk();
        $cursos->assertExactJson(['data' => [
            ['id' => Curso::query()->value('id'), 'texto' => 'COD-SEL-1 · Curso Selector (3 cupos)', 'precio' => '10.00', 'eliminado' => false],
        ]]);
        $this->assertStringNotContainsString('Descripcion privada del curso', $cursos->getContent());
    }
}
