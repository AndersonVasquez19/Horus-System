<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\ProcesoInterno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Filtros de listados del panel: una URL manipulada (parámetro con forma de array) no debe
 * provocar un error 500, y las búsquedas de texto no distinguen mayúsculas (whereLike, que
 * en PostgreSQL compila a ILIKE). Datos ficticios.
 */
class AdminListFilterInputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listFilters(): iterable
    {
        foreach ([
            'admin.estudiantes.index' => ['busqueda', 'estado'],
            'admin.cursos.index' => ['busqueda', 'estado', 'categoria'],
            'admin.matriculas.index' => ['busqueda', 'estado', 'curso_id'],
            'admin.matriculas.csv' => ['busqueda', 'estado', 'curso_id'],
            'admin.procesos.index' => ['busqueda', 'estado'],
            'admin.aprobaciones.index' => ['busqueda', 'estado'],
            'admin.usuarios.index' => ['busqueda'],
            'admin.auditoria.index' => ['accion', 'modelo', 'usuario'],
            'admin.busqueda.index' => ['q'],
        ] as $route => $params) {
            foreach ($params as $param) {
                yield "{$route} {$param}[]" => [$route, $param];
            }
        }
    }

    #[DataProvider('listFilters')]
    public function test_array_shaped_filters_never_cause_a_server_error(string $route, string $param): void
    {
        $this->actingAs($this->adminUser())
            ->get(route($route, [$param => ['x', 'y']]))
            ->assertOk();
    }

    /**
     * Con datos en los selectores, la vista compara cada opción con el filtro recibido: un
     * curso_id con forma de array no debe llegar a una conversión a texto.
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
            ->get(route('admin.matriculas.index', ['curso_id' => [(string) $curso->id]]))
            ->assertOk()
            ->assertSee('Curso ficticio de filtros');

        // El filtro válido se conserva: la opción queda seleccionada.
        $this->get(route('admin.matriculas.index', ['curso_id' => (string) $curso->id]))
            ->assertOk()
            ->assertSee('value="'.$curso->id.'" selected', false);
    }

    public function test_process_and_approval_status_filters_still_work(): void
    {
        $admin = $this->adminUser();
        ProcesoInterno::create(['titulo' => 'Proceso pendiente ficticio', 'estado' => 'pendiente', 'prioridad' => 'media']);
        ProcesoInterno::create(['titulo' => 'Proceso cancelado ficticio', 'estado' => 'cancelado', 'prioridad' => 'baja']);
        AprobacionInterna::create(['titulo' => 'Solicitud aprobada ficticia', 'tipo' => 'compra', 'estado' => 'aprobado']);
        AprobacionInterna::create(['titulo' => 'Solicitud rechazada ficticia', 'tipo' => 'compra', 'estado' => 'rechazado']);

        $this->actingAs($admin)->get(route('admin.procesos.index', ['estado' => 'cancelado']))
            ->assertOk()->assertSee('Proceso cancelado ficticio')->assertDontSee('Proceso pendiente ficticio');
        $this->get(route('admin.aprobaciones.index', ['estado' => 'aprobado']))
            ->assertOk()->assertSee('Solicitud aprobada ficticia')->assertDontSee('Solicitud rechazada ficticia');

        // Comportamiento actual conservado: un estado desconocido filtra por ese valor (lista vacía).
        $this->get(route('admin.procesos.index', ['estado' => 'inexistente']))
            ->assertOk()->assertDontSee('Proceso cancelado ficticio')->assertDontSee('Proceso pendiente ficticio');
    }

    public function test_text_searches_in_remaining_modules_ignore_ascii_case(): void
    {
        $admin = $this->adminUser();
        User::factory()->create(['name' => 'ROSA FICTICIA', 'email' => 'rosa.ficticia@example.invalid']);
        ProcesoInterno::create(['titulo' => 'REVISION DE SILABOS', 'estado' => 'pendiente', 'prioridad' => 'media']);
        AprobacionInterna::create(['titulo' => 'COMPRA DE PROYECTOR', 'tipo' => 'compra', 'estado' => 'pendiente']);
        AuditLog::record('EXPORTACION_FICTICIA', null, null, [], $admin->id);

        $this->actingAs($admin)->get(route('admin.usuarios.index', ['busqueda' => 'rosa']))->assertOk()->assertSee('ROSA FICTICIA');
        $this->get(route('admin.procesos.index', ['busqueda' => 'silabos']))->assertOk()->assertSee('REVISION DE SILABOS');
        $this->get(route('admin.aprobaciones.index', ['busqueda' => 'proyector']))->assertOk()->assertSee('COMPRA DE PROYECTOR');
        $this->get(route('admin.auditoria.index', ['accion' => 'exportacion']))->assertOk()->assertSee('EXPORTACION_FICTICIA');
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
