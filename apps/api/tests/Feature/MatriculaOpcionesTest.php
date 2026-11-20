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
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Selectores de estudiante y curso del panel de matrículas con búsqueda en el servidor: permisos por contexto,
 * elegibilidad (la misma de siempre), límite, escape de comodines y que las páginas ya no lleven las listas
 * completas. Datos ficticios. SQLite en memoria.
 */
class MatriculaOpcionesTest extends TestCase
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

    public function test_exige_sesion_y_rol_del_panel(): void
    {
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertUnauthorized();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])
            ->assertForbidden();
    }

    public function test_cada_contexto_exige_el_permiso_de_su_pantalla(): void
    {
        $this->seedRoles();
        // La secretaria tiene matriculas.ver y matriculas.crear, pero no matriculas.editar.
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');
        $this->actingAs($secretaria);

        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertOk();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'crear'])->assertOk();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertOk();
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'editar'])->assertForbidden();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'editar'])->assertForbidden();

        Role::findByName('secretaria')->revokePermissionTo('matriculas.crear');
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertForbidden();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertOk();

        Role::findByName('secretaria')->revokePermissionTo('matriculas.ver');
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertForbidden();
    }

    public function test_el_contexto_es_obligatorio_y_el_de_filtro_solo_aplica_a_cursos(): void
    {
        $this->actingAs($this->adminUser());

        $this->panelMatriculas('GET', 'opciones/estudiantes', [])->assertStatus(422);
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'otro'])->assertStatus(422);
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => ['crear']])->assertStatus(422);
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'filtro'])->assertStatus(422);
        $this->panelMatriculas('GET', 'opciones/cursos', [])->assertStatus(422);
    }

    public function test_devuelve_como_maximo_veinte_opciones_ordenadas_y_busca_en_el_servidor(): void
    {
        foreach (range(1, 25) as $n) {
            $this->estudiante(sprintf('7100%04d', $n), 'Nombre'.$n, sprintf('Apellido%02d', $n));
        }
        $this->estudiante('72000000', 'Rosa', 'Zapata');
        $this->actingAs($this->adminUser());

        $todos = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertOk()->json('data');
        $this->assertCount(OpcionesMatricula::LIMITE, $todos);
        $this->assertSame('71000001 · Nombre1 Apellido01', $todos[0]['texto']);
        $this->assertSame(['id', 'texto', 'eliminado'], array_keys($todos[0]));

        $buscados = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => 'zapa'])->json('data');
        $this->assertSame(['72000000 · Rosa Zapata'], array_column($buscados, 'texto'));

        $porDni = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => '71000025'])->json('data');
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

        $porcentaje = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => '%'])->json('data');
        $this->assertSame(['73000001 · Ana 100% Real Soto'], array_column($porcentaje, 'texto'));

        $guion = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => 'Cris_T'])->json('data');
        $this->assertSame(['73000002 · Cris_Tina Rojas'], array_column($guion, 'texto'));

        $cursos = $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro', 'q' => '%'])->json('data');
        $this->assertSame(['C-100 · Excel al 100% práctico'], array_column($cursos, 'texto'));
    }

    public function test_un_texto_con_forma_de_array_se_trata_como_vacio_sin_error(): void
    {
        $this->estudiante('74000001', 'Ana', 'Uno');
        $this->actingAs($this->adminUser());

        $opciones = $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => ['x']])->assertOk()->json('data');
        $this->assertCount(1, $opciones);
    }

    public function test_elegibilidad_de_estudiantes_por_contexto(): void
    {
        $activo = $this->estudiante('75000001', 'Activo', 'Uno');
        $inactivo = $this->estudiante('75000002', 'Inactivo', 'Dos', 'inactivo');
        $eliminado = $this->estudiante('75000003', 'Eliminado', 'Tres');
        $eliminado->delete();
        $this->actingAs($this->adminUser());

        $crear = array_column($this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->json('data'), 'id');
        $this->assertSame([$activo->id], $crear);

        $editar = array_column($this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'editar'])->json('data'), 'id');
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

        $crear = $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'crear'])->json('data');
        $this->assertSame([$disponible->id], array_column($crear, 'id'));
        $this->assertSame('C-1 · Disponible (5 cupos)', $crear[0]['texto']);

        $editar = array_column($this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'editar'])->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$disponible->id, $sinCupos->id, $inactivo->id], $editar);

        // El filtro del listado incluye los eliminados y no muestra cupos.
        $filtro = $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->json('data');
        $this->assertEqualsCanonicalizing([$disponible->id, $sinCupos->id, $inactivo->id, $eliminado->id], array_column($filtro, 'id'));
        $textos = array_column($filtro, 'texto', 'id');
        $this->assertSame('C-4 · Eliminado (eliminado)', $textos[$eliminado->id]);
        $this->assertSame('C-1 · Disponible', $textos[$disponible->id]);
    }

    /**
     * El formulario de edición lo sirve apps/web: parte del detalle de la matrícula. Aunque el estudiante y el curso
     * actuales estén eliminados, el detalle los entrega (marcados como eliminados) para que el selector los conserve.
     */
    public function test_el_detalle_conserva_el_estudiante_y_el_curso_actuales_aunque_esten_eliminados(): void
    {
        $estudiante = $this->estudiante('77000001', 'Actual', 'Eliminada');
        $curso = $this->curso('C-9', 'Curso actual');
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
        ]);
        $estudiante->delete();
        $curso->delete();

        $this->actingAs($this->adminUser())
            ->panelMatriculas('GET', (string) $matricula->id)
            ->assertOk()
            ->assertJsonPath('data.estudiante.id', $estudiante->id)
            ->assertJsonPath('data.estudiante.eliminado', true)
            ->assertJsonPath('data.curso.id', $curso->id)
            ->assertJsonPath('data.curso.eliminado', true);
    }

    public function test_ni_las_opciones_ni_el_listado_llevan_las_listas_completas(): void
    {
        foreach (range(1, 60) as $n) {
            $this->estudiante(sprintf('7800%04d', $n), 'Masivo', 'Registro'.$n);
            $this->curso('MAS-'.$n, 'Curso masivo '.$n);
        }
        Matricula::create([
            'estudiante_id' => Estudiante::query()->value('id'), 'curso_id' => Curso::query()->value('id'),
            'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
        ]);
        $this->actingAs($this->adminUser());

        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertOk()->assertJsonCount(20, 'data');
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'crear'])->assertOk()->assertJsonCount(20, 'data');

        // El listado solo devuelve matrículas, nunca la lista de cursos o estudiantes.
        $listado = $this->panelMatriculas('GET', '', [])->assertOk();
        $listado->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('Curso masivo 40', (string) $listado->getContent());
    }

    public function test_las_opciones_tienen_su_propio_limite_de_velocidad_por_usuario(): void
    {
        $this->actingAs($this->adminUser());

        foreach (range(1, 120) as $intento) {
            $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertOk();
        }
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro'])->assertTooManyRequests();
    }

    public function test_la_validacion_de_escritura_sigue_siendo_la_autoridad(): void
    {
        $inactivo = $this->estudiante('70000008', 'Inactivo', 'Uno', 'inactivo');
        $curso = $this->curso('C-1', 'Disponible');

        $this->actingAs($this->adminUser())
            ->panelMatriculas('POST', '', [
                'estudiante_id' => $inactivo->id, 'curso_id' => $curso->id,
                'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente',
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('estudiante_id');

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
