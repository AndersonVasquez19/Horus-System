<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\TestCase;

/**
 * Filtros de listados del panel: una URL manipulada (parámetro con forma de array) no debe
 * provocar un error 500, y las búsquedas de texto no distinguen mayúsculas (whereLike, que
 * en PostgreSQL compila a ILIKE). Datos ficticios.
 */
class AdminListFilterInputTest extends TestCase
{
    use LlamaMatriculasDelPanel;
    use LlamaUsuariosDelPanel;
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listFilters(): iterable
    {
        foreach ([
            'admin.busqueda.index' => ['q'],
        ] as $route => $params) {
            foreach ($params as $param) {
                yield "{$route} {$param}[]" => [$route, $param];
            }
        }
    }

    public function test_el_filtro_de_usuarios_del_panel_tolera_formas_de_arreglo(): void
    {
        $this->actingAs($this->adminUser())
            ->llamarUsuariosPanel('GET', '', ['busqueda' => ['x', 'y']])
            ->assertOk();
    }

    #[DataProvider('listFilters')]
    public function test_array_shaped_filters_never_cause_a_server_error(string $route, string $param): void
    {
        $this->actingAs($this->adminUser())
            ->get(route($route, [$param => ['x', 'y']]))
            ->assertOk();
    }

    /**
     * Con datos presentes, un curso_id con forma de array no debe llegar a una conversión a texto.
     */
    public function test_enrollment_course_filter_tolerates_arrays_when_courses_exist(): void
    {
        $curso = Curso::create([
            'codigo' => 'FLT-1', 'nombre' => 'Curso ficticio de filtros', 'descripcion' => 'Ficticio.',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 50, 'modalidad' => 'virtual',
            'horario' => 'Lunes', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        ]);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->panelMatriculas('GET', '', ['curso_id' => [(string) $curso->id]])
            ->assertOk()
            // Un filtro con forma de array se trata como vacío: no se aplica ni se devuelve como filtro efectivo.
            ->assertJsonPath('meta.filtros', []);

        // El filtro válido se conserva y se devuelve como filtro efectivo.
        $this->panelMatriculas('GET', '', ['curso_id' => (string) $curso->id])
            ->assertOk()
            ->assertJsonPath('meta.filtros.curso_id', $curso->id);
    }

    /** Matrículas ya no vive en Blade: sus filtros con forma de array se prueban contra el panel. */
    public function test_los_filtros_de_matriculas_con_forma_de_array_no_causan_un_error_de_servidor(): void
    {
        $this->actingAs($this->adminUser());
        foreach (['busqueda', 'estado', 'curso_id'] as $parametro) {
            $this->panelMatriculas('GET', '', [$parametro => ['x', 'y']])->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_text_searches_in_remaining_modules_ignore_ascii_case(): void
    {
        $admin = $this->adminUser();
        User::factory()->create(['name' => 'ROSA FICTICIA', 'email' => 'rosa.ficticia@example.invalid']);

        $this->actingAs($admin)->llamarUsuariosPanel('GET', '', ['busqueda' => 'rosa'])->assertOk()->assertJsonPath('data.0.nombre', 'ROSA FICTICIA');
    }

    /**
     * Guarda de regresión: SQLite no distingue mayúsculas ASCII con LIKE y PostgreSQL sí, así que
     * la suite no lo detecta por comportamiento. Ningún controlador del panel debe usar 'like' literal.
     */
    public function test_no_admin_controller_uses_a_literal_like_operator(): void
    {
        foreach (glob(app_path('Http/Controllers/Admin/*.php')) ?: [] as $archivo) {
            $codigo = file_get_contents($archivo);
            $this->assertIsString($codigo);
            $this->assertDoesNotMatchRegularExpression('/,\s*[\'"]like[\'"]\s*,/i', $codigo, basename($archivo)." usa 'like' literal.");
        }
    }
}
