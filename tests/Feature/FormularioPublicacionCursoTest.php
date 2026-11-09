<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FormularioPublicacionCursoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        // En UTC ya es el día siguiente: los valores deben seguir el día de Lima.
        $this->travelTo(Carbon::parse('2026-11-08 02:00:00', 'UTC'));
    }

    public function test_las_fechas_sugeridas_del_formulario_permiten_mostrar_el_curso_desde_hoy(): void
    {
        $curso = $this->curso();
        $editor = $this->actingAs($this->adminUser())->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()
            ->assertDontSee('Nombre administrativo reservado')->assertDontSee('Horario reservado');
        $xpath = $this->xpath($editor->getContent());
        $desde = $xpath->evaluate('string(//input[@name="vigente_desde"]/@value)');
        $hasta = $xpath->evaluate('string(//input[@name="vigente_hasta"]/@value)');
        $this->assertSame('2026-11-07', $desde);
        $this->assertSame('2026-12-07', $hasta);
        $this->assertSame('', $xpath->evaluate('string(//input[@name="titulo"]/@value)'));
        $this->assertSame(0, $xpath->query('//details[@open]')->length);
        $this->assertDatabaseCount('curso_publicaciones', 0);

        $this->put(route('admin.cursos.publicacion.update', $curso), [
            ...$this->datos(), 'vigente_desde' => $desde, 'vigente_hasta' => $hasta,
            '_revision' => $editor->headers->get('ETag'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        Auth::logout();
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('Curso público revisado');
        Http::assertNothingSent();
    }

    public function test_una_ficha_programada_conserva_sus_fechas_y_explica_por_que_no_se_muestra(): void
    {
        $curso = $this->publicar(['vigente_desde' => '2026-11-09', 'vigente_hasta' => '2026-11-11']);
        $editor = $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()
            ->assertSee('Publicación programada: se mostrará desde el 09/11/2026.')
            ->assertSee('cambia la fecha de inicio a 07/11/2026');
        $xpath = $this->xpath($editor->getContent());
        $this->assertSame('2026-11-09', $xpath->evaluate('string(//input[@name="vigente_desde"]/@value)'));
        $this->assertSame('2026-11-11', $xpath->evaluate('string(//input[@name="vigente_hasta"]/@value)'));
        $this->assertSame(1, $xpath->query('//details[@id="fechas-publicacion"][@open]')->length);
        Auth::logout();
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Curso público revisado');
        $this->assertSame('2026-11-09', CursoPublicacion::firstOrFail()->vigente_desde->toDateString());
    }

    public function test_una_publicacion_vencida_muestra_el_aviso_y_abre_sus_fechas(): void
    {
        $curso = $this->publicar();
        $this->travelTo(Carbon::parse('2026-12-08 12:00:00', 'America/Lima'));
        $editor = $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()
            ->assertSee('Publicación vencida: dejó de mostrarse después del 07/12/2026.');
        $this->assertSame(1, $this->xpath($editor->getContent())->query('//details[@id="fechas-publicacion"][@open]')->length);
    }

    public function test_los_errores_abren_las_opciones_y_conservan_la_entrada_sin_confirmar_otra_vez(): void
    {
        $curso = $this->curso();
        $url = route('admin.cursos.publicacion.edit', $curso);
        $this->actingAs($this->adminUser())->from($url)
            ->put(route('admin.cursos.publicacion.update', $curso), [
                ...$this->datos(), 'vigente_hasta' => '2026-11-06', 'horario' => str_repeat('x', 201),
            ])->assertSessionHasErrors(['vigente_hasta', 'horario'])->assertRedirect($url);
        $editor = $this->get($url)->assertOk();
        $xpath = $this->xpath($editor->getContent());
        $this->assertSame(2, $xpath->query('//details[@open]')->length);
        $this->assertSame('2026-11-06', $xpath->evaluate('string(//input[@name="vigente_hasta"]/@value)'));
        $this->assertSame('Curso público revisado', $xpath->evaluate('string(//input[@name="titulo"]/@value)'));
        $this->assertSame(0, $xpath->query('//input[@name="confirmacion_publica"][@checked]')->length);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_los_detalles_opcionales_guardados_se_conservan_al_editar(): void
    {
        $curso = $this->publicar(['horario' => 'Lunes de 18 a 20', 'inscripcion' => 'Consulta en Contacto.']);
        $editor = $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk();
        $xpath = $this->xpath($editor->getContent());
        $this->assertSame('Lunes de 18 a 20', $xpath->evaluate('string(//textarea[@name="horario"])'));
        $this->assertSame('Consulta en Contacto.', $xpath->evaluate('string(//textarea[@name="inscripcion"])'));
        $this->assertSame('2026-12-07', $xpath->evaluate('string(//input[@name="vigente_hasta"]/@value)'));
    }

    public function test_el_aviso_respeta_el_ultimo_dia_completo_de_vigencia_en_lima(): void
    {
        $curso = $this->publicar(['vigente_hasta' => '2026-11-07']);
        $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()
            ->assertSee('Ficha autorizada y vigente hasta el 07/11/2026.')
            ->assertDontSee('Publicación vencida')->assertDontSee('Publicación programada');
    }

    public function test_al_republicar_una_ficha_retirada_se_advierten_sus_fechas_vencidas(): void
    {
        $curso = $this->publicar(['vigente_hasta' => '2026-11-07']);
        $this->delete(route('admin.cursos.publicacion.destroy', $curso))->assertRedirect();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'America/Lima'));
        $editor = $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()
            ->assertSee('Las fechas guardadas están vencidas. Revísalas antes de publicar.');
        $this->assertSame(1, $this->xpath($editor->getContent())->query('//details[@id="fechas-publicacion"][@open]')->length);
    }

    private function xpath(string $html): DOMXPath
    {
        $documento = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function curso(): Curso
    {
        return Curso::create([
            'codigo' => 'FORM-1', 'nombre' => 'Nombre administrativo reservado', 'descripcion' => 'Descripción interna.',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150,
            'modalidad' => 'virtual', 'horario' => 'Horario reservado',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);
    }

    /** @return array<string, string> */
    private function datos(): array
    {
        return [
            'titulo' => 'Curso público revisado', 'resumen' => 'Resumen revisado para visitantes.',
            'modalidad' => 'virtual', 'vigente_desde' => '2026-11-07', 'vigente_hasta' => '2026-12-07',
            'confirmacion_publica' => '1',
        ];
    }

    /** @param array<string, string> $cambios */
    private function publicar(array $cambios = []): Curso
    {
        $curso = $this->curso();
        $this->actingAs($this->adminUser())->put(route('admin.cursos.publicacion.update', $curso), [
            ...$this->datos(), ...$cambios,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $curso;
    }
}
