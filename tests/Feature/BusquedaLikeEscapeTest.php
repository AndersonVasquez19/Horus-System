<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\BusquedaLike;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Los buscadores del panel tratan el texto escrito como literal: un `%` o un `_` no actúan como comodín.
 * Datos ficticios. SQLite prueba la lógica; la sintaxis PostgreSQL se fija compilando sin conexión.
 */
class BusquedaLikeEscapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_termino_escapa_los_caracteres_especiales_de_like(): void
    {
        $this->assertSame('%ana%', BusquedaLike::termino('ana'));
        $this->assertSame('%50!%%', BusquedaLike::termino('50%'));
        $this->assertSame('%a!_b%', BusquedaLike::termino('a_b'));
        $this->assertSame('%!!%', BusquedaLike::termino('!'));
        $this->assertSame('%!%!_!!%', BusquedaLike::termino('%_!'));
        $this->assertSame('%ñandú%', BusquedaLike::termino('ñandú'));
    }

    public function test_contiene_con_texto_vacio_no_agrega_condiciones(): void
    {
        $consulta = Estudiante::query();
        BusquedaLike::contiene($consulta, ['nombres'], '');

        $this->assertSame([], $consulta->getQuery()->wheres);
    }

    public function test_contiene_compila_a_ilike_con_escape_y_cast_a_texto_en_postgresql_sin_conectarse(): void
    {
        $conexion = new PostgresConnection(function (): never {
            throw new RuntimeException('Esta prueba nunca debe conectarse a PostgreSQL.');
        }, 'sin-conexion', '', ['name' => 'pgsql-solo-compilacion']);

        $consulta = (new QueryBuilder($conexion))->from('matriculas');
        BusquedaLike::contiene($consulta, ['nombres', 'id'], '50%_', comoTexto: ['id']);

        $this->assertSame(
            'select * from "matriculas" where ("nombres" ILIKE ? ESCAPE \'!\' or CAST("id" AS TEXT) ILIKE ? ESCAPE \'!\')',
            $consulta->toSql(),
        );
        $this->assertSame(['%50!%!_%', '%50!%!_%'], $consulta->getBindings());
    }

    public function test_la_condicion_se_agrupa_y_no_rompe_los_demas_filtros(): void
    {
        $this->crearEstudiante('70000001', 'Ana', 'López', 'activo');
        $this->crearEstudiante('70000002', 'Ana', 'Ruiz', 'inactivo');

        $consulta = Estudiante::query()->where('estado', 'activo');
        BusquedaLike::contiene($consulta, ['nombres', 'apellidos'], 'ana');

        $this->assertSame(['López'], $consulta->pluck('apellidos')->all());
    }

    public function test_estudiantes_busca_el_porcentaje_y_el_guion_bajo_como_texto(): void
    {
        $this->crearEstudiante('70000001', 'Ana 100% Real', 'Soto');
        $this->crearEstudiante('70000002', 'Cris_Tina', 'Rojas');
        $this->crearEstudiante('70000003', 'Cristina', 'Vega');
        $this->crearEstudiante('70000004', 'Beto', 'Quispe');
        $admin = $this->adminUser();

        $porcentaje = $this->actingAs($admin)->get(route('admin.estudiantes.index', ['busqueda' => '%']))->assertOk()->getContent();
        $this->assertStringContainsString('Soto', $porcentaje);
        foreach (['Rojas', 'Vega', 'Quispe'] as $ajeno) {
            $this->assertStringNotContainsString($ajeno, $porcentaje);
        }

        $guion = $this->get(route('admin.estudiantes.index', ['busqueda' => '_']))->assertOk()->getContent();
        $this->assertStringContainsString('Rojas', $guion);
        foreach (['Soto', 'Vega', 'Quispe'] as $ajeno) {
            $this->assertStringNotContainsString($ajeno, $guion);
        }

        $exacto = $this->get(route('admin.estudiantes.index', ['busqueda' => 'Cris_T']))->assertOk()->getContent();
        $this->assertStringContainsString('Rojas', $exacto);
        $this->assertStringNotContainsString('Vega', $exacto);

        $signo = $this->get(route('admin.estudiantes.index', ['busqueda' => '!']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Quispe', $signo);
    }

    public function test_las_busquedas_normales_siguen_funcionando_en_parcial_y_sin_distinguir_mayusculas(): void
    {
        $this->crearEstudiante('70000001', 'JUAN', 'PEREZ');
        $this->crearEstudiante('70000002', 'Rosa', 'Vega');
        $admin = $this->adminUser();

        $html = $this->actingAs($admin)->get(route('admin.estudiantes.index', ['busqueda' => 'uan']))->assertOk()->getContent();
        $this->assertStringContainsString('PEREZ', $html);
        $this->assertStringNotContainsString('Vega', $html);

        $html = $this->get(route('admin.estudiantes.index', ['busqueda' => '7000000']))->assertOk()->getContent();
        $this->assertStringContainsString('PEREZ', $html);
        $this->assertStringContainsString('Vega', $html);
    }

    public function test_cursos_busca_el_porcentaje_y_el_guion_bajo_como_texto(): void
    {
        $this->crearCurso('C-100', 'Excel al 100% práctico');
        $this->crearCurso('C-A_B', 'Taller guion');
        $this->crearCurso('C-AXB', 'Taller libre');
        $admin = $this->adminUser();

        $porcentaje = $this->actingAs($admin)->get(route('admin.cursos.index', ['busqueda' => '%']))->assertOk()->getContent();
        $this->assertStringContainsString('Excel al 100% práctico', $porcentaje);
        $this->assertStringNotContainsString('Taller guion', $porcentaje);
        $this->assertStringNotContainsString('Taller libre', $porcentaje);

        $guion = $this->get(route('admin.cursos.index', ['busqueda' => 'A_B']))->assertOk()->getContent();
        $this->assertStringContainsString('Taller guion', $guion);
        $this->assertStringNotContainsString('Taller libre', $guion);
    }

    public function test_usuarios_busca_el_porcentaje_y_el_guion_bajo_como_texto(): void
    {
        User::factory()->create(['name' => 'Marta 50% Pérez', 'email' => 'marta@example.test']);
        User::factory()->create(['name' => 'Nora_Díaz', 'email' => 'nora@example.test']);
        User::factory()->create(['name' => 'Noraadíaz', 'email' => 'otra@example.test']);
        $admin = $this->adminUser();

        $porcentaje = $this->actingAs($admin)->get(route('admin.usuarios.index', ['busqueda' => '%']))->assertOk()->getContent();
        $this->assertStringContainsString('marta@example.test', $porcentaje);
        $this->assertStringNotContainsString('nora@example.test', $porcentaje);
        $this->assertStringNotContainsString('otra@example.test', $porcentaje);

        $guion = $this->get(route('admin.usuarios.index', ['busqueda' => 'Nora_D']))->assertOk()->getContent();
        $this->assertStringContainsString('nora@example.test', $guion);
        $this->assertStringNotContainsString('otra@example.test', $guion);
    }

    public function test_matriculas_busca_por_estudiante_o_curso_sin_comodines(): void
    {
        $curso = $this->crearCurso('C-001', 'Curso común');
        $conPorcentaje = $this->matricular($this->crearEstudiante('70000001', 'Ana 100% Real', 'Soto'), $curso);
        $this->matricular($this->crearEstudiante('70000002', 'Beto', 'Quispe'), $curso);
        $admin = $this->actingAs($this->adminUser());

        $ids = $admin->get(route('admin.matriculas.index', ['busqueda' => '%']))->assertOk()->viewData('matriculas')->pluck('id')->all();
        $this->assertSame([$conPorcentaje->id], $ids);

        $cursoGuion = $this->crearCurso('C-A_B', 'Taller guion');
        $this->crearCurso('C-AXB', 'Taller libre');
        $delGuion = $this->matricular($this->crearEstudiante('70000003', 'Carla', 'Díaz'), $cursoGuion);
        $ids = $admin->get(route('admin.matriculas.index', ['busqueda' => 'A_B']))->assertOk()->viewData('matriculas')->pluck('id')->all();
        $this->assertSame([$delGuion->id], $ids);

        // Búsqueda normal por nombre de curso: las dos matrículas del «Curso común».
        $this->assertCount(2, $admin->get(route('admin.matriculas.index', ['busqueda' => 'común']))->assertOk()->viewData('matriculas'));
    }

    public function test_aprobaciones_busca_el_porcentaje_como_texto(): void
    {
        AprobacionInterna::create(['titulo' => 'Descuento del 30% ficticio', 'tipo' => 'compra', 'estado' => 'pendiente']);
        AprobacionInterna::create(['titulo' => 'Compra de sillas', 'tipo' => 'compra', 'estado' => 'pendiente']);

        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.aprobaciones.index', ['busqueda' => '%']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Descuento del 30% ficticio', $html);
        $this->assertStringNotContainsString('Compra de sillas', $html);
    }

    public function test_la_busqueda_global_no_trata_los_comodines_como_tales(): void
    {
        $curso = $this->crearCurso('C-100', 'Excel al 100% práctico');
        $this->crearCurso('C-LIBRE', 'Taller libre');
        $this->crearEstudiante('70000001', 'Ana 100% Real', 'Soto');
        $this->crearEstudiante('70000002', 'Beto', 'Quispe');
        User::factory()->create(['name' => 'Marta 50% Pérez', 'email' => 'marta@example.test']);
        $conPorcentaje = $this->matricular($this->crearEstudiante('70000003', 'Eva 7% Real', 'Mora'), $curso);
        $admin = $this->adminUser();

        $resultados = $this->actingAs($admin)
            ->get(route('admin.busqueda.index', ['q' => '%']))
            ->assertOk()
            ->viewData('resultados');

        $this->assertSame(['Soto', 'Mora'], $resultados['estudiantes']->pluck('apellidos')->sort()->reverse()->values()->all());
        $this->assertSame(['Excel al 100% práctico'], $resultados['cursos']->pluck('nombre')->all());
        $this->assertSame([$conPorcentaje->id], $resultados['matriculas']->pluck('id')->all());
        $this->assertSame(['Marta 50% Pérez'], $resultados['usuarios']->pluck('name')->all());

        // El id sigue buscándose como texto: «_» ya no equivale a «cualquier carácter».
        $this->assertSame([], $this->get(route('admin.busqueda.index', ['q' => '_']))->viewData('resultados')['matriculas']->pluck('id')->all());
    }

    public function test_auditoria_filtra_accion_modelo_y_usuario_sin_comodines(): void
    {
        $usuario = User::factory()->create(['name' => 'Elsa 5% Vega', 'email' => 'elsa@example.test']);
        User::factory()->create(['name' => 'Otro Nombre', 'email' => 'otro@example.test']);
        $this->registro('descuento_30%', 'App\\Models\\Curso', null);
        $this->registro('descuento_x30', 'App\\Models\\Curso', null);
        $this->registro('login', 'App\\Models\\User', $usuario->id);
        $admin = $this->adminUser();

        $acciones = fn (array $filtros): array => $this->actingAs($admin)
            ->get(route('admin.auditoria.index', $filtros))
            ->assertOk()
            ->viewData('registros')
            ->pluck('action')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['descuento_30%'], $acciones(['accion' => '%']));
        $this->assertSame(['descuento_30%'], $acciones(['accion' => '_30']));
        $this->assertSame(['descuento_30%', 'descuento_x30'], $acciones(['accion' => 'descuento']));
        $this->assertSame([], $acciones(['modelo' => 'Cur_o']));
        $this->assertSame(['descuento_30%', 'descuento_x30'], $acciones(['modelo' => 'Curso']));
        $this->assertSame(['login'], $acciones(['usuario' => '5%']));
        $this->assertSame([], $acciones(['usuario' => 'Elsa_5']));
    }

    // -- datos ficticios --------------------------------------------------------------------------------------

    private function crearEstudiante(string $dni, string $nombres, string $apellidos, string $estado = 'activo'): Estudiante
    {
        return Estudiante::create(['dni' => $dni, 'nombres' => $nombres, 'apellidos' => $apellidos, 'estado' => $estado]);
    }

    private function crearCurso(string $codigo, string $nombre): Curso
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

    private function matricular(Estudiante $estudiante, Curso $curso): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
        ]);
    }

    private function registro(string $accion, string $modelo, ?int $userId): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $accion,
            'model_type' => $modelo,
            'model_id' => null,
            'ip_address' => '127.0.0.1',
            'changes' => null,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}
