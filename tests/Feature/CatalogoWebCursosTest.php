<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CursosPublicosController;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use App\Services\CatalogoPublicoCursos;
use App\Services\ExportadorCatalogoAdvisor;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogoWebCursosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
    }

    public function test_original_public_route_and_safe_default_are_preserved(): void
    {
        $this->assertFalse(config('catalogo-publico.enabled'));
        $this->assertSame(url('/educacion/cursos'), route('educacion.cursos'));
        $route = app('router')->getRoutes()->getByName('educacion.cursos');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(CursosPublicosController::class, $route->getActionName());
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('MODALIDADES DE CURSOS')
            ->assertSee('SEMIPRESENCIAL')->assertDontSee('id="catalogo-cursos"', false);
        Http::assertNothingSent();
    }

    public function test_disabled_without_editorial_schema_never_calls_catalog_or_database(): void
    {
        Schema::drop('curso_publicaciones');
        $this->mock(CatalogoPublicoCursos::class)->shouldNotReceive('paraWeb');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('MODALIDADES DE CURSOS')
            ->assertDontSee('CURSOS PUBLICADOS');
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_enabled_renders_only_public_fields_and_existing_contact_action_without_writes(): void
    {
        $this->ficha();
        config(['catalogo-publico.enabled' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('Redes autorizadas')
            ->assertSee('Modalidad: Virtual')->assertSee('Consultar curso')->assertSee(route('contactos'))
            ->assertSee('MODALIDADES DE CURSOS')->assertDontSee('SECRETO')->assertDontSee('9876.54')
            ->assertDontSee('autorizado_por')->assertDontSee('curso_id');
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
        }
        $this->assertDatabaseCount('matriculas', 0);
        Http::assertNothingSent();
    }

    public static function excluidas(): iterable
    {
        foreach (['borrador', 'retirada', 'caducada', 'futura', 'inactivo', 'archivado', 'eliminado', 'autorizacion_futura', 'sin_autor', 'sin_permiso'] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('excluidas')]
    public function test_nonpublic_content_is_excluded(string $caso): void
    {
        [$autor, $curso, $ficha] = $this->ficha();
        match ($caso) {
            'borrador', 'retirada' => $ficha->update(['publicada' => false]),
            'caducada' => $ficha->update(['vigente_hasta' => '2026-10-28']),
            'futura' => $ficha->update(['vigente_desde' => '2026-10-30']),
            'inactivo' => $curso->update(['estado' => 'inactivo']),
            'archivado' => $curso->update(['estado' => 'archivado']),
            'eliminado' => $curso->delete(),
            'autorizacion_futura' => $ficha->update(['autorizado_at' => now()->addDay()]),
            'sin_autor' => $ficha->update(['autorizado_por' => null]),
            'sin_permiso' => $autor->removeRole('admin'),
        };
        config(['catalogo-publico.enabled' => true]);
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Redes autorizadas')
            ->assertSee('No hay fichas de cursos publicadas vigentes.');
        Http::assertNothingSent();
    }

    public function test_empty_catalog_keeps_modalities_and_never_invents_courses(): void
    {
        config(['catalogo-publico.enabled' => true]);
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('No hay fichas de cursos publicadas vigentes.')
            ->assertSee('PRESENCIAL')->assertSee('SEMIPRESENCIAL')->assertSee('VIRTUAL')
            ->assertDontSee('<article class="carta-card', false);
    }

    public function test_optional_fields_are_omitted_and_published_text_is_escaped(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['titulo' => '<script>alert(1)</script>', 'resumen' => '<img src=x onerror=alert(2)>',
            'horario' => '<b>Horario publicado</b>', 'inscripcion' => 'javascript:alert(3)']);
        config(['catalogo-publico.enabled' => true]);
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('<script>alert(1)</script>')
            ->assertSee('<img src=x onerror=alert(2)>')->assertSee('<b>Horario publicado</b>')
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('href="javascript:', false);
        $ficha->update(['horario' => null, 'inscripcion' => null]);
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Horario:')->assertDontSee('Información de inscripción:');
    }

    public function test_missing_schema_and_query_errors_are_controlled_and_safely_logged(): void
    {
        config(['catalogo-publico.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('catalogo_web_esquema_incompleto');
        Schema::drop('curso_publicaciones');
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('No podemos mostrar los cursos publicados')
            ->assertDontSee('SQLSTATE')->assertDontSee('curso_publicaciones');
    }

    public function test_incomplete_columns_are_controlled_without_logging_sql_or_bindings(): void
    {
        config(['catalogo-publico.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('catalogo_web_lectura_fallida');
        $this->mock(CatalogoPublicoCursos::class)->shouldReceive('paraWeb')->once()
            ->andThrow(new QueryException('sqlite', 'SELECT SECRETO', ['SECRETO'], new \Exception('SECRETO')));
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('No podemos mostrar los cursos publicados')
            ->assertDontSee('SECRETO');
    }

    public function test_invalid_legacy_public_fields_fail_closed(): void
    {
        [, , $ficha] = $this->ficha();
        $ficha->update(['modalidad' => 'inventada']);
        config(['catalogo-publico.enabled' => true]);
        Log::shouldReceive('warning')->once()->with('catalogo_web_ficha_invalida');
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Redes autorizadas');
    }

    public function test_deterministic_limit_is_visible_and_query_count_does_not_grow_per_card(): void
    {
        [$autor] = $this->ficha(['titulo' => 'Ficha 00']);
        config(['catalogo-publico.enabled' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('educacion.cursos'))->assertOk();
        $queries = count(DB::getQueryLog());
        for ($i = 1; $i <= 50; $i++) {
            $this->ficha(['titulo' => sprintf('Ficha %02d', $i)], $autor);
        }
        DB::flushQueryLog();
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('51 fichas vigentes. Mostrando 1–50')
            ->assertSeeInOrder(['Ficha 00', 'Ficha 01', 'Ficha 49'])->assertDontSee('Ficha 50');
        $this->assertLessThanOrEqual($queries, count(DB::getQueryLog()));
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'join "cursos"')));
    }

    public function test_editor_publication_and_withdrawal_match_web_and_advisor_export(): void
    {
        [$autor, $curso] = $this->ficha();
        config(['catalogo-publico.enabled' => true, 'horus-advisor.signing_key' => str_repeat('k', 32)]);
        $this->actingAs($autor);
        $revision = $this->get(route('admin.cursos.publicacion.edit', $curso))->headers->get('ETag');
        $this->put(route('admin.cursos.publicacion.update', $curso), [
            '_revision' => $revision, 'titulo' => 'Curso revisado', 'resumen' => 'Información editorial autorizada.',
            'modalidad' => 'presencial', 'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-10-30', 'confirmacion_publica' => '1',
        ])->assertRedirect();
        $this->assertSame('Curso revisado', app(ExportadorCatalogoAdvisor::class)->construir($autor)['items'][0]['title']);
        auth()->forgetGuards();
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('Curso revisado');
        $this->actingAs($autor)->delete(route('admin.cursos.publicacion.destroy', $curso))->assertRedirect();
        $this->assertSame([], app(ExportadorCatalogoAdvisor::class)->construir($autor)['items']);
        auth()->forgetGuards();
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Curso revisado');
        $this->assertDatabaseCount('matriculas', 0);
        Http::assertNothingSent();
    }

    private function ficha(array $cambios = [], ?User $autor = null): array
    {
        $autor ??= $this->adminUser();
        $curso = Curso::create(['codigo' => 'SECRETO-'.Curso::count(), 'nombre' => 'SECRETO administrativo',
            'descripcion' => 'SECRETO privado', 'categoria' => 'SECRETO', 'duracion_horas' => 10, 'precio' => 9876.54,
            'modalidad' => 'virtual', 'horario' => 'SECRETO horario', 'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'activo']);
        $ficha = CursoPublicacion::create(array_replace(['curso_id' => $curso->id, 'titulo' => 'Redes autorizadas',
            'resumen' => 'Información editorial autorizada.', 'modalidad' => 'virtual', 'horario' => null, 'inscripcion' => null,
            'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-10-30', 'publicada' => true,
            'autorizado_por' => $autor->id, 'autorizado_at' => now()], $cambios));

        return [$autor, $curso, $ficha];
    }
}
