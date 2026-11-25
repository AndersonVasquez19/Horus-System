<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use App\Services\CatalogoPublicoCursos;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CursosPublicosBff;
use Tests\Support\LlamaCursosDelPanel;
use Tests\TestCase;

class CursoPublicacionImagenTest extends TestCase
{
    use CursosPublicosBff;
    use LlamaCursosDelPanel;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Storage::fake('public');
    }

    private function crearCursoActivo(): Curso
    {
        return Curso::create([
            'codigo' => 'CUR-IMG-01',
            'nombre' => 'Curso Imagen Referencial',
            'descripcion' => 'Descripción interna de prueba.',
            'categoria' => 'tecnologia',
            'modalidad' => 'presencial',
            'duracion_horas' => 24,
            'horario' => 'Lunes y miércoles 19:00-21:00',
            'precio' => 250.00,
            'cupos_totales' => 30,
            'cupos_disponibles' => 30,
            'estado' => 'activo',
        ]);
    }

    public function test_admin_puede_subir_reemplazar_y_eliminar_imagen_referencial_de_curso_publicado(): void
    {
        $curso = $this->crearCursoActivo();
        $this->actingAs($this->admin);

        $headers = ['X-Horus-Web-Token' => $this->tokenPanelCursos()];

        $revision = (string) $this->withHeaders($headers)
            ->getJson("/api/panel/v1/academico/cursos/{$curso->id}/publicacion")
            ->assertOk()
            ->json('data.revision');

        $hoy = now('America/Lima')->toDateString();
        $vigenteHasta = now('America/Lima')->addDays(15)->toDateString();
        $inicio = now('America/Lima')->addDays(2)->toDateString();

        $revision = (string) $this->withHeaders($headers)
            ->putJson("/api/panel/v1/academico/cursos/{$curso->id}/publicacion", [
                'titulo' => 'Curso con imagen',
                'resumen' => 'Resumen editorial del curso.',
                'modalidad' => 'presencial',
                'fecha_inicio' => $inicio,
                'duracion' => '24 horas · 4 semanas',
                'certificacion' => 'Certificado físico y digital',
                'temario' => "Módulo 1\nMódulo 2",
                'vigente_desde' => $hoy,
                'vigente_hasta' => $vigenteHasta,
                'confirmacion_publica' => true,
                '_revision' => $revision,
            ])->assertOk()->json('data.revision');

        $primera = UploadedFile::fake()->image('curso-1.png', 600, 400);
        $respuestaSubida = $this->withHeaders($headers)
            ->post("/api/panel/v1/academico/cursos/{$curso->id}/publicacion/imagen", [
                'imagen' => $primera,
                '_revision' => $revision,
            ])->assertOk();

        $ficha = CursoPublicacion::where('curso_id', $curso->id)->firstOrFail();
        $rutaPrimera = $ficha->imagen_ruta;
        $this->assertNotNull($rutaPrimera);
        Storage::disk('public')->assertExists($rutaPrimera);
        $this->assertNotNull($respuestaSubida->json('data.ficha.imagen_url'));

        config(['catalogo-publico.enabled' => true]);
        $catalogo = app(CatalogoPublicoCursos::class)->paraWeb();
        $this->assertSame($respuestaSubida->json('data.ficha.imagen_url'), $catalogo['cursos'][0]['imagen_url']);
        $this->assertSame($inicio, $catalogo['cursos'][0]['fecha_inicio']);
        $this->assertSame('24 horas · 4 semanas', $catalogo['cursos'][0]['duracion']);
        $this->assertSame('Certificado físico y digital', $catalogo['cursos'][0]['certificacion']);
        $this->assertSame("Módulo 1\nMódulo 2", $catalogo['cursos'][0]['temario']);

        // Reemplazar la imagen borra la anterior del disco.
        $revision2 = (string) $respuestaSubida->json('data.revision');
        $segunda = UploadedFile::fake()->image('curso-2.jpg', 600, 400);
        $respuestaReemplazo = $this->withHeaders($headers)
            ->post("/api/panel/v1/academico/cursos/{$curso->id}/publicacion/imagen", [
                'imagen' => $segunda,
                '_revision' => $revision2,
            ])->assertOk();

        $rutaSegunda = $ficha->fresh()->imagen_ruta;
        $this->assertNotNull($rutaSegunda);
        $this->assertNotSame($rutaPrimera, $rutaSegunda);
        Storage::disk('public')->assertMissing($rutaPrimera);
        Storage::disk('public')->assertExists($rutaSegunda);

        // Eliminar la imagen la retira de la ficha y del disco.
        $revision3 = (string) $respuestaReemplazo->json('data.revision');
        $respuestaEliminacion = $this->withHeaders($headers)
            ->postJson("/api/panel/v1/academico/cursos/{$curso->id}/publicacion/imagen/eliminar", [
                '_revision' => $revision3,
            ])->assertOk();

        $this->assertNull($ficha->fresh()->imagen_ruta);
        $this->assertNull($respuestaEliminacion->json('data.ficha.imagen_url'));
        Storage::disk('public')->assertMissing($rutaSegunda);
    }

    public function test_rechaza_revision_caducada_y_usuario_sin_permiso(): void
    {
        $curso = $this->crearCursoActivo();
        $coordinador = User::factory()->create();
        $coordinador->assignRole('coordinador');

        $this->actingAs($coordinador)
            ->withHeaders(['X-Horus-Web-Token' => $this->tokenPanelCursos()])
            ->post("/api/panel/v1/academico/cursos/{$curso->id}/publicacion/imagen", [
                'imagen' => UploadedFile::fake()->image('curso.png', 400, 300),
                '_revision' => '"'.str_repeat('a', 64).'"',
            ])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->withHeaders(['X-Horus-Web-Token' => $this->tokenPanelCursos()])
            ->post("/api/panel/v1/academico/cursos/{$curso->id}/publicacion/imagen", [
                'imagen' => UploadedFile::fake()->image('curso.png', 400, 300),
                '_revision' => '"'.str_repeat('a', 64).'"',
            ])
            ->assertStatus(412);
    }
}
