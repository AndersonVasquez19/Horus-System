<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CursosPorModalidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{string}> */
    public static function modalidades(): iterable
    {
        foreach (['presencial', 'virtual', 'semipresencial'] as $modalidad) {
            yield $modalidad => [$modalidad];
        }
    }

    public function test_la_web_muestra_el_catalogo_por_defecto_sin_activar_el_catalogo_del_chat(): void
    {
        $this->assertTrue(config('catalogo-publico.enabled'));
        $this->assertFalse(config('chatbot.public_catalog_enabled'));
        $this->get(route('educacion.cursos'))->assertOk()
            ->assertSee('CURSOS PUBLICADOS')
            ->assertSee('No hay fichas de cursos publicadas vigentes.');
        $this->assertDatabaseCount('curso_publicaciones', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('modalidades')]
    public function test_crear_revisar_publicar_filtrar_y_retirar_un_curso(string $modalidad): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->get(route('admin.cursos.create'))->assertOk()
            ->assertSee('value="'.$modalidad.'"', false);
        $this->post(route('admin.cursos.store'), $this->datosCurso($modalidad))->assertSessionHasNoErrors()->assertRedirect();
        $curso = Curso::firstOrFail();
        $this->assertSame($modalidad, $curso->modalidad);
        $this->assertDatabaseCount('curso_publicaciones', 0);
        $this->get(route('admin.cursos.show', $curso))->assertOk()
            ->assertSee('Publicación web')
            ->assertSee(route('admin.cursos.publicacion.edit', $curso));

        Auth::logout();
        $this->get(route('educacion.cursos', ['modalidad' => $modalidad]))->assertOk()
            ->assertDontSee('Curso público de prueba')->assertDontSee('Nombre administrativo reservado');

        $editor = $this->actingAs($admin)->get(route('admin.cursos.publicacion.edit', $curso))->assertOk();
        $this->put(route('admin.cursos.publicacion.update', $curso), [
            ...$this->datosPublicos($modalidad), '_revision' => $editor->headers->get('ETag'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(CursoPublicacion::firstOrFail()->publicada);

        Auth::logout();
        $this->get(route('educacion.cursos', ['modalidad' => $modalidad]))->assertOk()
            ->assertSee('Curso público de prueba')->assertSee('Horario público confirmado')
            ->assertDontSee('Nombre administrativo reservado')->assertDontSee('Descripción administrativa reservada')
            ->assertDontSee('Horario administrativo reservado')->assertDontSee('9876.54');
        $otraModalidad = $modalidad === 'virtual' ? 'presencial' : 'virtual';
        $this->get(route('educacion.cursos', ['modalidad' => $otraModalidad]))->assertOk()
            ->assertDontSee('Curso público de prueba');

        $editor = $this->actingAs($admin)->get(route('admin.cursos.publicacion.edit', $curso))->assertOk();
        $this->delete(route('admin.cursos.publicacion.destroy', $curso), [
            '_revision' => $editor->headers->get('ETag'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        Auth::logout();
        $this->get(route('educacion.cursos', ['modalidad' => $modalidad]))->assertOk()->assertDontSee('Curso público de prueba');
        $this->assertDatabaseCount('matriculas', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('modalidades')]
    public function test_editar_un_curso_conserva_la_modalidad_elegida_en_el_formulario(string $modalidad): void
    {
        $curso = $this->curso('hibrido');
        $this->actingAs($this->adminUser())->put(route('admin.cursos.update', $curso), $this->datosCurso($modalidad))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($modalidad, $curso->fresh()->modalidad);
        $this->get(route('admin.cursos.edit', $curso))->assertOk()
            ->assertSee('value="'.$modalidad.'" selected', false);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_hibrido_sigue_siendo_valido_y_no_se_reclasifica_automaticamente(): void
    {
        $this->actingAs($this->adminUser())->post(route('admin.cursos.store'), $this->datosCurso('hibrido'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $curso = Curso::firstOrFail();
        $this->put(route('admin.cursos.update', $curso), $this->datosCurso('hibrido'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('admin.cursos.edit', $curso))->assertOk()->assertSee('value="hibrido" selected', false);
        $this->assertSame('hibrido', $curso->fresh()->modalidad);
    }

    public function test_publicar_sigue_exigiendo_confirmacion_explicita(): void
    {
        $curso = $this->curso('semipresencial');
        $datos = $this->datosPublicos('semipresencial');
        unset($datos['confirmacion_publica']);
        $this->actingAs($this->adminUser())->putJson(route('admin.cursos.publicacion.update', $curso), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmacion_publica');
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_el_acceso_a_publicar_requiere_su_permiso_propio(): void
    {
        $this->adminUser();
        $coordinador = User::factory()->create()->assignRole('coordinador');
        $curso = $this->curso('semipresencial');
        $this->actingAs($coordinador)->get(route('admin.cursos.show', $curso))->assertOk()->assertDontSee('Publicación web');
        $this->get(route('admin.cursos.publicacion.edit', $curso))->assertForbidden();
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datosPublicos('semipresencial'))->assertForbidden();
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_una_modalidad_desconocida_se_rechaza_en_creacion_y_edicion(): void
    {
        $curso = $this->curso('semipresencial');
        $this->actingAs($this->adminUser())->postJson(route('admin.cursos.store'), $this->datosCurso('desconocida', ['codigo' => 'OTRO']))
            ->assertUnprocessable()->assertJsonValidationErrors('modalidad');
        $this->putJson(route('admin.cursos.update', $curso), $this->datosCurso('desconocida'))
            ->assertUnprocessable()->assertJsonValidationErrors('modalidad');
        $this->assertDatabaseCount('cursos', 1);
        $this->assertSame('semipresencial', $curso->fresh()->modalidad);
    }

    private function curso(string $modalidad): Curso
    {
        return Curso::create([...$this->datosCurso($modalidad), 'cupos_disponibles' => 10]);
    }

    /** @return array<string, int|string> */
    private function datosCurso(string $modalidad, array $cambios = []): array
    {
        return array_replace([
            'codigo' => 'CUR-MOD', 'nombre' => 'Nombre administrativo reservado', 'descripcion' => 'Descripción administrativa reservada',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => '9876.54', 'modalidad' => $modalidad,
            'horario' => 'Horario administrativo reservado', 'cupos_maximos' => 10, 'estado' => 'activo',
        ], $cambios);
    }

    /** @return array<string, string> */
    private function datosPublicos(string $modalidad): array
    {
        return [
            'titulo' => 'Curso público de prueba', 'resumen' => 'Contenido editorial revisado para la prueba aislada.',
            'modalidad' => $modalidad, 'horario' => 'Horario público confirmado', 'inscripcion' => 'Consulta las condiciones de inscripción.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addDay()->toDateString(),
            'confirmacion_publica' => '1',
        ];
    }
}
