<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LlamaCursosDelPanel;
use Tests\TestCase;

/**
 * Editor de la ficha pública de un curso por la API del panel (`/api/panel/v1/academico/cursos/{id}/publicacion`).
 * Sustituye a las pruebas del editor Blade retirado el 14/11/2026. Lo que dependía del HTML (el enlace del listado,
 * los dos campos `_revision` ocultos, el borrador conservado en sesión y el botón «Cargar versión actual») pasa a la UI
 * de Next; aquí quedan las garantías de servidor: permiso propio, revisión obligatoria (cuerpo o cabecera), 412 sin
 * escribir, una revisión obsoleta no pisa la versión de otro administrador y nada se publica sin precondición válida.
 */
class EditorPublicacionPanelTest extends TestCase
{
    use LlamaCursosDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    public function test_el_editor_se_abre_tambien_para_un_curso_eliminado_y_lo_marca_no_publicable(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->fichaCursoPanel($curso)->assertOk()->assertJsonPath('data.publicable', true);
        $curso->delete();
        $this->fichaCursoPanel($curso)->assertOk()->assertJsonPath('data.publicable', false);
    }

    public static function personal(): iterable
    {
        yield 'coordinador sin permiso' => ['coordinador', false];
        yield 'secretaria sin permiso' => ['secretaria', false];
        yield 'coordinador autorizado' => ['coordinador', true];
    }

    #[DataProvider('personal')]
    public function test_el_acceso_sigue_al_permiso_y_no_solo_al_rol(string $rol, bool $permitido): void
    {
        $this->adminUser();
        $user = User::factory()->create();
        $user->assignRole($rol);
        if ($permitido) {
            $user->givePermissionTo('cursos.publicar');
        }
        $curso = $this->curso();
        $this->actingAs($user);

        $this->fichaCursoPanel($curso)->assertStatus($permitido ? 200 : 403);
        $this->publicarFichaCursoPanel($curso, $this->datos())->assertStatus($permitido ? 200 : 403);
        if (! $permitido) {
            $this->retirarFichaCursoPanel($curso)->assertForbidden();
            $this->assertDatabaseCount('curso_publicaciones', 0);
        }
    }

    public function test_guardar_y_retirar_funcionan_con_la_revision_en_el_cuerpo_como_lo_envia_el_bff(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $revision = $this->revisionFichaCursoPanel($curso);

        $this->panelCursos('PUT', $curso->id.'/publicacion', $this->datos() + ['_revision' => $revision])->assertOk();
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Redes públicas', 'publicada' => true]);

        $editor = $this->fichaCursoPanel($curso)->assertOk();
        $this->assertSame($editor->headers->get('ETag'), $editor->json('data.revision'));
        $this->panelCursos('POST', $curso->id.'/publicacion/retirar', ['_revision' => $editor->headers->get('ETag')])->assertOk();
        $this->assertFalse(CursoPublicacion::firstOrFail()->publicada);
        Http::assertNothingSent();
    }

    public function test_una_revision_obsoleta_no_pisa_la_version_de_otro_administrador_ni_se_revalida_sola(): void
    {
        $admin = $this->adminUser();
        $curso = $this->curso();
        $this->actingAs($admin);
        $revision = $this->revisionFichaCursoPanel($curso);
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $this->actingAs($otro)->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Versión de otro administrador']))->assertOk();

        $this->actingAs($admin);
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Mi borrador']), $revision)->assertStatus(412);
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Versión de otro administrador', 'autorizado_por' => $otro->id]);
        // Reenviar el mismo borrador con la misma revisión no lo revalida automáticamente.
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Mi borrador']), $revision)->assertStatus(412);
        $this->assertDatabaseMissing('curso_publicaciones', ['titulo' => 'Mi borrador']);
        // Con la revisión actual sí se guarda.
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Mi borrador']))->assertOk();
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Mi borrador', 'autorizado_por' => $admin->id]);
    }

    public function test_un_retiro_obsoleto_conserva_la_publicacion_vigente_y_a_su_autor(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->publicarFichaCursoPanel($curso, $this->datos())->assertOk();
        $vieja = $this->revisionFichaCursoPanel($curso);
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Edición posterior']))->assertOk();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();

        $this->panelCursos('POST', $curso->id.'/publicacion/retirar', ['_revision' => $vieja])->assertStatus(412);
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public static function revisionesInvalidas(): iterable
    {
        yield 'array' => [['x']];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'malformed' => ['invalida'];
    }

    #[DataProvider('revisionesInvalidas')]
    public function test_una_revision_presente_pero_invalida_nunca_cae_en_una_escritura_incondicional(mixed $revision): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();

        $this->panelCursos('PUT', $curso->id.'/publicacion', $this->datos() + ['_revision' => $revision])
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_sin_ninguna_revision_el_panel_no_escribe(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->publicarFichaCursoPanel($curso, $this->datos())->assertOk();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();

        $this->panelCursos('PUT', $curso->id.'/publicacion', $this->datos(['titulo' => 'Sin precondición']))
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->panelCursos('POST', $curso->id.'/publicacion/retirar')->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public function test_cabecera_y_campo_del_cuerpo_se_comprueban_y_devuelven_412_ante_un_conflicto(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $revision = $this->revisionFichaCursoPanel($curso);

        $this->publicarFichaCursoPanel($curso, $this->datos(), $revision)->assertOk();
        $this->publicarFichaCursoPanel($curso, $this->datos(), $revision)->assertStatus(412);
        $this->panelCursos('PUT', $curso->id.'/publicacion', $this->datos() + ['_revision' => $revision])->assertStatus(412);
        $actual = $this->revisionFichaCursoPanel($curso);
        // Una cabecera y un campo que no coinciden son ambiguos: se rechazan sin escribir.
        $this->panelCursos('PUT', $curso->id.'/publicacion', $this->datos() + ['_revision' => $revision], ['If-Match' => $actual])->assertUnprocessable();
    }

    public function test_un_error_de_validacion_no_renueva_la_revision_tras_un_cambio_intermedio(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $vieja = $this->revisionFichaCursoPanel($curso);
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Cambio externo']))->assertOk();

        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => '']), $vieja)->assertUnprocessable()->assertJsonValidationErrors('titulo');
        $this->assertNotSame($vieja, $this->revisionFichaCursoPanel($curso));
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Cambio externo']);
    }

    private function curso(): Curso
    {
        return Curso::create(['codigo' => 'FIXTURE', 'nombre' => 'Curso administrativo ficticio', 'descripcion' => 'Descripción interna ficticia', 'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Interno', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    }

    private function datos(array $cambios = []): array
    {
        return array_replace(['titulo' => 'Redes públicas', 'resumen' => 'Contenido editorial ficticio y autorizado.', 'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addDay()->toDateString(), 'confirmacion_publica' => '1'], $cambios);
    }
}
