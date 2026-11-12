<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\OpcionesMatricula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Selectores de estudiante y curso del panel de matrículas con búsqueda en el servidor: permisos por contexto,
 * elegibilidad (la misma de siempre), límite, escape de comodines y que las páginas ya no lleven las listas
 * completas. Datos ficticios. SQLite en memoria.
 */
class MatriculaOpcionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_exige_sesion_y_rol_del_panel(): void
    {
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))->assertUnauthorized();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))
            ->assertForbidden();
    }

    public function test_cada_contexto_exige_el_permiso_de_su_pantalla(): void
    {
        $this->seedRoles();
        // La secretaria tiene matriculas.ver y matriculas.crear, pero no matriculas.editar.
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');
        $this->actingAs($secretaria);

        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))->assertOk();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'crear']))->assertOk();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']))->assertOk();
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'editar']))->assertForbidden();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'editar']))->assertForbidden();

        Role::findByName('secretaria')->revokePermissionTo('matriculas.crear');
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))->assertForbidden();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']))->assertOk();

        Role::findByName('secretaria')->revokePermissionTo('matriculas.ver');
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']))->assertForbidden();
    }

    public function test_el_contexto_es_obligatorio_y_el_de_filtro_solo_aplica_a_cursos(): void
    {
        $this->actingAs($this->adminUser());

        $this->getJson(route('admin.matriculas.opciones.estudiantes'))->assertStatus(422);
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'otro']))->assertStatus(422);
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => ['crear']]))->assertStatus(422);
        $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'filtro']))->assertStatus(422);
        $this->getJson(route('admin.matriculas.opciones.cursos'))->assertStatus(422);
    }

    public function test_devuelve_como_maximo_veinte_opciones_ordenadas_y_busca_en_el_servidor(): void
    {
        foreach (range(1, 25) as $n) {
            $this->estudiante(sprintf('7100%04d', $n), 'Nombre'.$n, sprintf('Apellido%02d', $n));
        }
        $this->estudiante('72000000', 'Rosa', 'Zapata');
        $this->actingAs($this->adminUser());

        $todos = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))->assertOk()->json('opciones');
        $this->assertCount(OpcionesMatricula::LIMITE, $todos);
        $this->assertSame('71000001 · Nombre1 Apellido01', $todos[0]['texto']);
        $this->assertSame(['id', 'texto', 'eliminado'], array_keys($todos[0]));

        $buscados = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear', 'q' => 'zapa']))->json('opciones');
        $this->assertSame(['72000000 · Rosa Zapata'], array_column($buscados, 'texto'));

        $porDni = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear', 'q' => '71000025']))->json('opciones');
        $this->assertSame(['71000025 · Nombre25 Apellido25'], array_column($porDni, 'texto'));
    }

    public function test_los_comodines_de_like_se_buscan_como_texto(): void
    {
        $this->estudiante('73000001', 'Ana 100% Real', 'Soto');
        $this->estudiante('73000002', 'Cris_Tina', 'Rojas');
        $this->estudiante('73000003', 'Cristina', 'Vega');
        $this->curso('C-100', 'Excel al 100% práctico');
        $this->curso('C-LIBRE', 'Taller libre');
        $this->actingAs($this->adminUser());

        $porcentaje = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear', 'q' => '%']))->json('opciones');
        $this->assertSame(['73000001 · Ana 100% Real Soto'], array_column($porcentaje, 'texto'));

        $guion = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear', 'q' => 'Cris_T']))->json('opciones');
        $this->assertSame(['73000002 · Cris_Tina Rojas'], array_column($guion, 'texto'));

        $cursos = $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro', 'q' => '%']))->json('opciones');
        $this->assertSame(['C-100 · Excel al 100% práctico'], array_column($cursos, 'texto'));
    }

    public function test_un_texto_con_forma_de_array_se_trata_como_vacio_sin_error(): void
    {
        $this->estudiante('74000001', 'Ana', 'Uno');
        $this->actingAs($this->adminUser());

        $opciones = $this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear', 'q' => ['x']]))->assertOk()->json('opciones');
        $this->assertCount(1, $opciones);
    }

    public function test_elegibilidad_de_estudiantes_por_contexto(): void
    {
        $activo = $this->estudiante('75000001', 'Activo', 'Uno');
        $inactivo = $this->estudiante('75000002', 'Inactivo', 'Dos', 'inactivo');
        $eliminado = $this->estudiante('75000003', 'Eliminado', 'Tres');
        $eliminado->delete();
        $this->actingAs($this->adminUser());

        $crear = array_column($this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'crear']))->json('opciones'), 'id');
        $this->assertSame([$activo->id], $crear);

        $editar = array_column($this->getJson(route('admin.matriculas.opciones.estudiantes', ['contexto' => 'editar']))->json('opciones'), 'id');
        // Orden por apellido: «Dos» (inactivo) antes que «Uno» (activo).
        $this->assertSame([$inactivo->id, $activo->id], $editar);
    }

    public function test_elegibilidad_de_cursos_por_contexto(): void
    {
        $disponible = $this->curso('C-1', 'Disponible');
        $sinCupos = $this->curso('C-2', 'Sin cupos', cupos: 0);
        $inactivo = $this->curso('C-3', 'Inactivo', estado: 'inactivo');
        $eliminado = $this->curso('C-4', 'Eliminado');
        $eliminado->delete();
        $this->actingAs($this->adminUser());

        $crear = $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'crear']))->json('opciones');
        $this->assertSame([$disponible->id], array_column($crear, 'id'));
        $this->assertSame('C-1 · Disponible (5 cupos)', $crear[0]['texto']);

        $editar = array_column($this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'editar']))->json('opciones'), 'id');
        $this->assertEqualsCanonicalizing([$disponible->id, $sinCupos->id, $inactivo->id], $editar);

        // El filtro del listado incluye los eliminados y no muestra cupos.
        $filtro = $this->getJson(route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']))->json('opciones');
        $this->assertEqualsCanonicalizing([$disponible->id, $sinCupos->id, $inactivo->id, $eliminado->id], array_column($filtro, 'id'));
        $textos = array_column($filtro, 'texto', 'id');
        $this->assertSame('C-4 · Eliminado (eliminado)', $textos[$eliminado->id]);
        $this->assertSame('C-1 · Disponible', $textos[$disponible->id]);
    }

    public function test_el_formulario_de_alta_usa_el_combobox_y_preselecciona_solo_registros_elegibles(): void
    {
        $activo = $this->estudiante('76000001', 'Activo', 'Uno');
        $inactivo = $this->estudiante('76000002', 'Inactivo', 'Dos', 'inactivo');
        $curso = $this->curso('C-1', 'Disponible');
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.matriculas.create', ['estudiante_id' => $activo->id, 'curso_id' => $curso->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-controls="estudiante_id-lista"', $html);
        $this->assertStringContainsString('x-data="selectorAsincrono"', $html);
        $this->assertStringContainsString('data-contexto="crear"', $html);
        $this->assertStringContainsString('<input type="hidden" name="estudiante_id" value="'.$activo->id.'"', $html);
        $this->assertStringContainsString('data-texto="76000001 · Activo Uno"', $html);
        $this->assertStringContainsString('data-texto="C-1 · Disponible (5 cupos)"', $html);
        $this->assertStringNotContainsString('<select id="estudiante_id"', $html);
        $this->assertStringNotContainsString('<select id="curso_id"', $html);

        // Un estudiante inactivo no es elegible para una alta: no se preselecciona, como antes.
        $sinSeleccion = (string) $this->get(route('admin.matriculas.create', ['estudiante_id' => $inactivo->id]))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="estudiante_id" value=""', $sinSeleccion);
        $this->assertStringNotContainsString('76000002', $sinSeleccion);
    }

    public function test_el_formulario_de_edicion_conserva_el_estudiante_y_el_curso_actuales_aunque_esten_eliminados(): void
    {
        $estudiante = $this->estudiante('77000001', 'Actual', 'Eliminada');
        $curso = $this->curso('C-9', 'Curso actual');
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
        ]);
        $estudiante->delete();
        $curso->delete();

        $html = (string) $this->actingAs($this->adminUser())
            ->get(route('admin.matriculas.edit', $matricula))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-contexto="editar"', $html);
        $this->assertStringContainsString('<input type="hidden" name="estudiante_id" value="'.$estudiante->id.'"', $html);
        $this->assertStringContainsString('data-texto="77000001 · Actual Eliminada (eliminado)"', $html);
        $this->assertStringContainsString('data-texto="C-9 · Curso actual ('.$curso->cupos_disponibles.' cupos) (eliminado)"', $html);
    }

    public function test_las_paginas_ya_no_llevan_las_listas_completas(): void
    {
        foreach (range(1, 60) as $n) {
            $this->estudiante(sprintf('7800%04d', $n), 'Masivo', 'Registro'.$n);
            $this->curso('MAS-'.$n, 'Curso masivo '.$n);
        }
        $matricula = Matricula::create([
            'estudiante_id' => Estudiante::query()->value('id'), 'curso_id' => Curso::query()->value('id'),
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
        ]);
        $this->actingAs($this->adminUser());

        $alta = (string) $this->get(route('admin.matriculas.create'))->assertOk()->getContent();
        $edicion = (string) $this->get(route('admin.matriculas.edit', $matricula))->assertOk()->getContent();

        foreach ([$alta, $edicion] as $html) {
            $this->assertStringNotContainsString('Registro40', $html);
            $this->assertStringNotContainsString('Curso masivo 40', $html);
        }

        // El listado solo resuelve el curso elegido en el filtro, y conserva las matrículas.
        $listado = $this->get(route('admin.matriculas.index'))->assertOk();
        $listado->assertViewMissing('cursos');
        $listado->assertDontSee('Curso masivo 40');
    }

    public function test_el_filtro_del_listado_usa_el_combobox_y_conserva_el_curso_elegido(): void
    {
        $curso = $this->curso('C-F', 'Curso filtrado');
        $this->actingAs($this->adminUser());

        $html = (string) $this->get(route('admin.matriculas.index', ['curso_id' => (string) $curso->id]))->assertOk()->getContent();

        $this->assertStringContainsString('id="filtro_curso_id"', $html);
        $this->assertStringContainsString('aria-label="Filtrar por curso"', $html);
        $this->assertStringContainsString('data-contexto="filtro"', $html);
        $this->assertStringContainsString('<input type="hidden" name="curso_id" value="'.$curso->id.'"', $html);
        $this->assertStringContainsString('data-texto="C-F · Curso filtrado"', $html);
        $this->assertStringNotContainsString('<select name="curso_id"', $html);

        $sinFiltro = (string) $this->get(route('admin.matriculas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="curso_id" value=""', $sinFiltro);
        $this->assertStringContainsString('placeholder="Todos los cursos"', $sinFiltro);
    }

    public function test_un_identificador_con_forma_de_array_no_se_preselecciona(): void
    {
        $this->estudiante('79000001', 'Ana', 'Uno');
        $this->actingAs($this->adminUser());

        $html = (string) $this->get(route('admin.matriculas.create', ['estudiante_id' => ['1'], 'curso_id' => ['1']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<input type="hidden" name="estudiante_id" value=""', $html);
        $this->assertStringContainsString('<input type="hidden" name="curso_id" value=""', $html);
    }

    public function test_el_alta_sin_estudiantes_o_cursos_elegibles_muestra_el_aviso_y_desactiva_guardar(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.matriculas.create'))
            ->assertOk()
            ->assertSee('No hay estudiantes activos disponibles.')
            ->assertSee('No hay cursos activos con cupos disponibles para esta matrícula.');

        $this->estudiante('70000009', 'Ana', 'Uno');
        $this->curso('C-1', 'Disponible');
        $this->get(route('admin.matriculas.create'))
            ->assertOk()
            ->assertDontSee('No hay estudiantes activos disponibles.')
            ->assertDontSee('No hay cursos activos con cupos disponibles');
    }

    public function test_las_opciones_tienen_su_propio_limite_de_velocidad_por_usuario(): void
    {
        $this->actingAs($this->adminUser());
        $url = route('admin.matriculas.opciones.cursos', ['contexto' => 'filtro']);

        foreach (range(1, 120) as $intento) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson($url)->assertTooManyRequests();
    }

    public function test_la_validacion_de_escritura_sigue_siendo_la_autoridad(): void
    {
        $inactivo = $this->estudiante('70000008', 'Inactivo', 'Uno', 'inactivo');
        $curso = $this->curso('C-1', 'Disponible');

        $this->actingAs($this->adminUser())
            ->post(route('admin.matriculas.store'), [
                'estudiante_id' => $inactivo->id, 'curso_id' => $curso->id,
                'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
            ])
            ->assertSessionHasErrors('estudiante_id');

        $this->assertDatabaseCount('matriculas', 0);
    }

    // -- datos ficticios --------------------------------------------------------------------------------------

    private function seedRoles(): void
    {
        $this->adminUser();
    }

    private function estudiante(string $dni, string $nombres, string $apellidos, string $estado = 'activo'): Estudiante
    {
        return Estudiante::create(['dni' => $dni, 'nombres' => $nombres, 'apellidos' => $apellidos, 'estado' => $estado]);
    }

    private function curso(string $codigo, string $nombre, int $cupos = 5, string $estado = 'activo'): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => 'Curso ficticio.',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual',
            'horario' => 'Lunes', 'cupos_maximos' => 5, 'cupos_disponibles' => $cupos, 'estado' => $estado,
        ]);
    }
}
