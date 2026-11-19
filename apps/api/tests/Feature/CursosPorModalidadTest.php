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
use Tests\Support\LlamaCursosDelPanel;
use Tests\Concerns\CursosPublicosBff;
use Tests\TestCase;

class CursosPorModalidadTest extends TestCase
{
    use LlamaCursosDelPanel;
    use CursosPublicosBff;
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
        $this->cursosWeb()->assertOk()->assertJsonPath('habilitado', true)->assertJsonPath('cursos', [])->assertJsonPath('total', 0);
        $this->assertDatabaseCount('curso_publicaciones', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('modalidades')]
    public function test_crear_revisar_publicar_filtrar_y_retirar_un_curso(string $modalidad): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $this->panelCursos('POST', '', $this->datosCurso($modalidad))->assertCreated();
        $curso = Curso::firstOrFail();
        $this->assertSame($modalidad, $curso->modalidad);
        $this->assertDatabaseCount('curso_publicaciones', 0);
        // El editor de la ficha parte vacío y conoce la modalidad del curso.
        $this->fichaCursoPanel($curso)->assertOk()->assertJsonPath('data.ficha', null)->assertJsonPath('data.curso.modalidad', $modalidad);

        Auth::logout();
        $web = $this->textoCursosWeb('?modalidad='.$modalidad);
        $this->assertStringNotContainsString('Curso público de prueba', $web);
        $this->assertStringNotContainsString('Nombre administrativo reservado', $web);

        $this->actingAs($admin);
        $this->publicarFichaCursoPanel($curso, $this->datosPublicos($modalidad))->assertOk();
        $this->assertTrue(CursoPublicacion::firstOrFail()->publicada);

        Auth::logout();
        $web = $this->textoCursosWeb('?modalidad='.$modalidad);
        $this->assertStringContainsString('Curso público de prueba', $web);
        $this->assertStringContainsString('Horario público confirmado', $web);
        foreach (['Nombre administrativo reservado', 'Descripción administrativa reservada', 'Horario administrativo reservado', '9876.54'] as $reservado) {
            $this->assertStringNotContainsString($reservado, $web);
        }
        $otraModalidad = $modalidad === 'virtual' ? 'presencial' : 'virtual';
        $this->assertStringNotContainsString('Curso público de prueba', $this->textoCursosWeb('?modalidad='.$otraModalidad));

        $this->actingAs($admin);
        $this->retirarFichaCursoPanel($curso)->assertOk();
        Auth::logout();
        $this->assertStringNotContainsString('Curso público de prueba', $this->textoCursosWeb('?modalidad='.$modalidad));
        $this->assertDatabaseCount('matriculas', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('modalidades')]
    public function test_editar_un_curso_conserva_la_modalidad_elegida_en_el_formulario(string $modalidad): void
    {
        $curso = $this->curso('hibrido');
        $this->actingAs($this->adminUser())->panelCursos('PUT', (string) $curso->id, $this->datosCurso($modalidad))
            ->assertOk();
        $this->assertSame($modalidad, $curso->fresh()->modalidad);
        $this->panelCursos('GET', (string) $curso->id)->assertOk()->assertJsonPath('data.modalidad', $modalidad);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_hibrido_sigue_siendo_valido_y_no_se_reclasifica_automaticamente(): void
    {
        $this->actingAs($this->adminUser())->panelCursos('POST', '', $this->datosCurso('hibrido'))
            ->assertCreated();
        $curso = Curso::firstOrFail();
        $this->panelCursos('PUT', (string) $curso->id, $this->datosCurso('hibrido'))
            ->assertOk();
        $this->panelCursos('GET', (string) $curso->id)->assertOk()->assertJsonPath('data.modalidad', 'hibrido');
        $this->assertSame('hibrido', $curso->fresh()->modalidad);
    }

    public function test_publicar_sigue_exigiendo_confirmacion_explicita(): void
    {
        $curso = $this->curso('semipresencial');
        $datos = $this->datosPublicos('semipresencial');
        unset($datos['confirmacion_publica']);
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($curso, $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmacion_publica');
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_el_acceso_a_publicar_requiere_su_permiso_propio(): void
    {
        $this->adminUser();
        $coordinador = User::factory()->create()->assignRole('coordinador');
        $curso = $this->curso('semipresencial');
        $this->actingAs($coordinador);
        $this->fichaCursoPanel($curso)->assertForbidden();
        $this->publicarFichaCursoPanel($curso, $this->datosPublicos('semipresencial'))->assertForbidden();
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_una_modalidad_desconocida_se_rechaza_en_creacion_y_edicion(): void
    {
        $curso = $this->curso('semipresencial');
        $this->actingAs($this->adminUser())->panelCursos('POST', '', $this->datosCurso('desconocida', ['codigo' => 'OTRO']))
            ->assertUnprocessable()->assertJsonValidationErrors('modalidad');
        $this->panelCursos('PUT', (string) $curso->id, $this->datosCurso('desconocida'))
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
