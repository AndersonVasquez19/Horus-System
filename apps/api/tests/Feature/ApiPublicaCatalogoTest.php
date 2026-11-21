<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Fase 2a: lectura pública JSON para el BFF de apps/web (`/api/public/v1/*`). */
class ApiPublicaCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-publico.enabled' => false, 'catalogo-servicios.enabled' => false]);
        Http::preventStrayRequests();
    }

    /** @return array<string, string> */
    private function cabecera(?string $token = self::TOKEN): array
    {
        return $token === null ? [] : ['X-Horus-Web-Token' => $token];
    }

    public function test_sin_token_configurado_o_corto_las_rutas_no_existen(): void
    {
        foreach (['', 'corto'] as $configurado) {
            config(['horus-web.service_token' => $configurado]);
            $this->getJson('/api/public/v1/cursos', $this->cabecera($configurado))->assertNotFound();
        }
    }

    public function test_token_ausente_o_incorrecto_responde_401_sin_revelar_nada(): void
    {
        foreach ([null, 'otro-token-otro-token-otro-token-otro!', substr(self::TOKEN, 0, -1)] as $token) {
            $this->getJson('/api/public/v1/cursos', $this->cabecera($token))->assertUnauthorized()
                ->assertJsonMissing(['cursos'])->assertDontSee(self::TOKEN);
        }
        $this->getJson('/api/public/v1/servicios')->assertUnauthorized();
        $this->getJson('/api/public/v1/paginas/camaras-seguridad')->assertUnauthorized();
    }

    public function test_solo_get_y_head_y_nunca_se_cachea(): void
    {
        $this->postJson('/api/public/v1/cursos', [], $this->cabecera())->assertStatus(405);
        $this->getJson('/api/public/v1/cursos', $this->cabecera())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_catalogo_apagado_responde_vacio_sin_consultar_datos(): void
    {
        $this->getJson('/api/public/v1/cursos', $this->cabecera())->assertOk()
            ->assertExactJson(['habilitado' => false, 'modalidad' => null, 'total' => 0, 'cursos' => []]);
        $this->getJson('/api/public/v1/servicios', $this->cabecera())->assertOk()
            ->assertJsonPath('habilitado', false)->assertJsonPath('servicios', []);
        $this->getJson('/api/public/v1/paginas/cableado-estructurado', $this->cabecera())->assertOk()
            ->assertExactJson(['pagina' => 'cableado-estructurado', 'contenido' => null]);
    }

    public function test_cursos_publicados_solo_exponen_campos_editoriales_y_filtran_por_modalidad(): void
    {
        config(['catalogo-publico.enabled' => true]);
        $autor = $this->adminUser();
        $this->ficha($autor, ['titulo' => 'Curso virtual', 'modalidad' => 'virtual']);
        $this->ficha($autor, ['titulo' => 'Curso presencial', 'modalidad' => 'presencial']);
        $this->ficha($autor, ['titulo' => 'Curso sin publicar', 'publicada' => false]);

        $todos = $this->getJson('/api/public/v1/cursos', $this->cabecera())->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(2, 'cursos');
        $this->assertSame(['Curso presencial', 'Curso virtual'], array_column($todos->json('cursos'), 'titulo'));
        $todos->assertDontSee('SECRETO')->assertDontSee('9876')->assertDontSee('cupos');

        $this->getJson('/api/public/v1/cursos?modalidad=virtual', $this->cabecera())->assertOk()
            ->assertJsonPath('modalidad', 'virtual')->assertJsonPath('total', 1)
            ->assertJsonPath('cursos.0.titulo', 'Curso virtual');
        $this->getJson('/api/public/v1/cursos?modalidad=inventada', $this->cabecera())->assertStatus(422);
    }

    public function test_falla_tecnica_del_catalogo_responde_503_generico(): void
    {
        config(['catalogo-publico.enabled' => true]);
        Schema::drop('curso_publicaciones');

        $this->getJson('/api/public/v1/cursos', $this->cabecera())->assertStatus(503)
            ->assertExactJson(['error' => ['code' => 'catalogo_no_disponible', 'message' => 'El catálogo no está disponible.']]);
    }

    public function test_servicios_valida_la_pagina_y_pagina_desconocida_es_404(): void
    {
        $this->getJson('/api/public/v1/servicios?pagina=0', $this->cabecera())->assertStatus(422);
        $this->getJson('/api/public/v1/servicios?pagina=abc', $this->cabecera())->assertStatus(422);
        $this->getJson('/api/public/v1/paginas/market', $this->cabecera())->assertNotFound();
    }

    public function test_el_resto_de_la_api_no_cambia(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertCount(12, collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/public/v1')));
    }

    /** @param array<string, mixed> $cambios */
    private function ficha(User $autor, array $cambios = []): CursoPublicacion
    {
        $curso = Curso::create(['codigo' => 'SECRETO-'.Curso::count(), 'nombre' => 'SECRETO administrativo',
            'descripcion' => 'SECRETO privado', 'categoria' => 'SECRETO', 'duracion_horas' => 10, 'precio' => 9876.54,
            'modalidad' => 'virtual', 'horario' => 'SECRETO horario', 'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'activo']);

        return CursoPublicacion::create(array_replace(['curso_id' => $curso->id, 'titulo' => 'Redes autorizadas',
            'resumen' => 'Información editorial autorizada.', 'modalidad' => 'virtual', 'horario' => null, 'inscripcion' => null,
            'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-10-30', 'publicada' => true,
            'autorizado_por' => $autor->id, 'autorizado_at' => now()], $cambios));
    }
}
