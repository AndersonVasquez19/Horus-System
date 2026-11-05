<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Búsquedas del panel que deben comportarse igual en SQLite (pruebas) y PostgreSQL (destino).
 * Datos ficticios.
 */
class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_keeps_partial_matching_by_enrollment_id(): void
    {
        $curso = $this->makeCourse('CUR-A', 'Contabilidad básica');
        $matriculas = collect(range(1, 11))->map(
            fn (int $n) => $this->makeEnrollment($this->makeStudent(sprintf('2000%04d', $n), 'Ana', 'Ficticia'), $curso),
        );

        $ids = $this->actingAs($this->adminUser())
            ->get(route('admin.busqueda.index', ['q' => '1']))
            ->assertOk()
            ->viewData('resultados')['matriculas']
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        // Comportamiento previo conservado: "1" encuentra los IDs que lo contienen (1, 10, 11).
        $esperados = $matriculas->pluck('id')->filter(fn (int $id) => str_contains((string) $id, '1'))->values()->all();
        $this->assertSame($esperados, $ids);
        $this->assertCount(3, $ids);
    }

    /**
     * Fija la gramática PostgreSQL instalada de la que dependen las búsquedas: whereLike() sin
     * distinción de mayúsculas compila a ILIKE con conversión a texto (válida también para el ID
     * entero). Se compila sin abrir ninguna conexión: el PDO lanza un error si se resolviera.
     */
    public function test_installed_postgresql_grammar_compiles_where_like_to_text_ilike(): void
    {
        $connection = new PostgresConnection(function (): never {
            throw new RuntimeException('Esta prueba nunca debe conectarse a PostgreSQL.');
        }, 'sin-conexion', '', ['name' => 'pgsql-solo-compilacion']);

        $sql = (new QueryBuilder($connection))
            ->from('matriculas')
            ->whereLike('id', '%7%')
            ->orWhereLike('nombres', '%juan%')
            ->toSql();

        $this->assertSame('select * from "matriculas" where "id"::text ilike ? or "nombres"::text ilike ?', $sql);
    }

    public function test_global_search_by_text_still_finds_enrollments_by_student_or_course(): void
    {
        $curso = $this->makeCourse('CUR-C', 'Gestión de proyectos');
        $porNombre = $this->makeEnrollment($this->makeStudent('20000101', 'Rosa', 'Ficticia'), $curso);
        $otra = $this->makeEnrollment($this->makeStudent('20000102', 'Luis', 'Inventado'), $this->makeCourse('CUR-D', 'Ofimática'));

        $resultados = $this->actingAs($this->adminUser())
            ->get(route('admin.busqueda.index', ['q' => 'rosa']))
            ->assertOk()
            ->viewData('resultados');

        $this->assertSame([$porNombre->id], $resultados['matriculas']->pluck('id')->all());
        $this->assertNotContains($otra->id, $resultados['matriculas']->pluck('id')->all());

        $this->assertSame(
            ['Ofimática'],
            $this->actingAs($this->adminUser())
                ->get(route('admin.busqueda.index', ['q' => 'OFIM']))
                ->viewData('resultados')['cursos']->pluck('nombre')->all(),
        );
    }

    public function test_module_lists_search_without_distinguishing_ascii_case(): void
    {
        $curso = $this->makeCourse('CUR-E', 'REDES Y SERVIDORES');
        $this->makeEnrollment($this->makeStudent('20000201', 'JUAN', 'PEREZ'), $curso);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.estudiantes.index', ['busqueda' => 'juan']))
            ->assertOk()
            ->assertSee('PEREZ');

        $this->actingAs($admin)
            ->get(route('admin.cursos.index', ['busqueda' => 'redes']))
            ->assertOk()
            ->assertSee('REDES Y SERVIDORES');

        $this->assertCount(1, $this->actingAs($admin)
            ->get(route('admin.matriculas.index', ['busqueda' => 'perez']))
            ->assertOk()
            ->viewData('matriculas'));
    }

    /**
     * SQLite compara LIKE sin distinguir mayúsculas ASCII y PostgreSQL no; las pruebas no ven
     * esa diferencia. whereLike() compila a ILIKE en PostgreSQL, así que estos módulos no deben
     * volver a usar el operador "like" literal.
     */
    public function test_module_searches_use_the_portable_case_insensitive_like(): void
    {
        foreach (['Busqueda', 'Estudiante', 'Curso', 'Matricula'] as $modulo) {
            $codigo = file_get_contents(app_path("Http/Controllers/Admin/{$modulo}Controller.php"));

            $this->assertIsString($codigo);
            $this->assertDoesNotMatchRegularExpression('/,\s*[\'"]like[\'"]\s*,/i', $codigo, "{$modulo}Controller usa 'like' literal.");
        }
    }

    private function makeStudent(string $dni, string $nombres, string $apellidos): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'estado' => 'activo',
        ]);
    }

    private function makeCourse(string $codigo, string $nombre): Curso
    {
        return Curso::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => 'Curso ficticio para pruebas de búsqueda.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 30,
            'cupos_disponibles' => 30,
            'estado' => 'activo',
        ]);
    }

    private function makeEnrollment(Estudiante $estudiante, Curso $curso): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
        ]);
    }
}
