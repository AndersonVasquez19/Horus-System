<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditorPublicacionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    public function test_authorized_link_uses_existing_route_including_deleted_courses(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->get(route('admin.cursos.index'))->assertOk()->assertSee('href="'.route('admin.cursos.publicacion.edit', $curso).'"', false);
        $curso->delete();
        $this->get(route('admin.cursos.index', ['eliminados' => 1]))->assertOk()->assertSee('href="'.route('admin.cursos.publicacion.edit', $curso).'"', false);
        $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk();
    }

    public static function personal(): iterable
    {
        yield 'coordinador sin permiso' => ['coordinador', false];
        yield 'secretaria sin permiso' => ['secretaria', false];
        yield 'coordinador autorizado' => ['coordinador', true];
    }

    #[DataProvider('personal')]
    public function test_link_and_backend_follow_permission_not_just_role(string $rol, bool $permitido): void
    {
        $this->adminUser();
        $user = User::factory()->create();
        $user->assignRole($rol);
        if ($permitido) {
            $user->givePermissionTo('cursos.publicar');
        }
        $curso = $this->curso();
        $url = route('admin.cursos.publicacion.edit', $curso);
        $listado = $this->actingAs($user)->get(route('admin.cursos.index'))->assertOk();
        $permitido ? $listado->assertSee('href="'.$url.'"', false) : $listado->assertDontSee('href="'.$url.'"', false);
        $this->get($url)->assertStatus($permitido ? 200 : 403);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos())->assertStatus($permitido ? 302 : 403);
        if (! $permitido) {
            $this->deleteJson(route('admin.cursos.publicacion.destroy', $curso))->assertForbidden();
        }
    }

    public function test_editor_sends_same_revision_in_both_forms_and_native_save_and_withdraw_work(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $revision = $this->revision($curso);
        $this->put(route('admin.cursos.publicacion.update', $curso), $this->datos() + ['_revision' => $revision])->assertRedirect();
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Redes públicas', 'publicada' => true]);
        $editor = $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk();
        $revision = $editor->headers->get('ETag');
        $this->assertSame(2, substr_count($editor->getContent(), 'name="_revision"'));
        $this->delete(route('admin.cursos.publicacion.destroy', $curso), ['_revision' => $revision])->assertRedirect();
        $this->assertFalse(CursoPublicacion::firstOrFail()->publicada);
        Http::assertNothingSent();
    }

    public function test_stale_native_form_keeps_draft_and_old_revision_until_explicit_reload(): void
    {
        $admin = $this->adminUser();
        $curso = $this->curso();
        $this->actingAs($admin);
        $revision = $this->revision($curso);
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $this->actingAs($otro)->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => 'Versión de otro administrador']))->assertRedirect();
        $url = route('admin.cursos.publicacion.edit', $curso);
        $this->actingAs($admin)->from($url)->put(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => 'Mi borrador conservado']) + ['_revision' => $revision])
            ->assertRedirect($url)->assertSessionHasErrors('revision')->assertSessionHasInput('titulo', 'Mi borrador conservado')->assertSessionHasInput('_revision', $revision);
        $editor = $this->get($url)->assertOk()->assertSee('Mi borrador conservado')->assertSee('Cargar versión actual')->assertSee('La ficha cambió');
        $this->assertStringContainsString('value="'.e($revision).'"', $editor->getContent());
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Versión de otro administrador', 'autorizado_por' => $otro->id]);
        // Reenviar el mismo borrador no lo revalida automáticamente.
        $this->put(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => 'Mi borrador conservado']) + ['_revision' => $revision])->assertSessionHasErrors('revision');
        $this->get($url)->assertOk();
        $this->get($url)->assertOk()->assertSee('Versión de otro administrador')->assertDontSee('Mi borrador conservado');
    }

    public function test_stale_withdrawal_preserves_current_publication_and_author(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos())->assertRedirect();
        $vieja = $this->revision($curso);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => 'Edición posterior']))->assertRedirect();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();
        $this->delete(route('admin.cursos.publicacion.destroy', $curso), ['_revision' => $vieja])->assertSessionHasErrors('revision');
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
    public function test_present_invalid_form_revision_cannot_fall_back_to_unconditional_write(mixed $revision): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos() + ['_revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_json_form_revision_and_existing_header_contract_return_412_on_conflict(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $revision = $this->revision($curso);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos(), ['If-Match' => $revision])->assertRedirect();
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos(), ['If-Match' => $revision])->assertStatus(412);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos() + ['_revision' => $revision])->assertStatus(412);
        $actual = $this->revision($curso);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos() + ['_revision' => $revision], ['If-Match' => $actual])->assertUnprocessable();
    }

    public function test_validation_error_does_not_refresh_hidden_revision_after_an_intervening_change(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $vieja = $this->revision($curso);
        $this->putJson(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => 'Cambio externo']))->assertRedirect();
        $url = route('admin.cursos.publicacion.edit', $curso);
        $this->from($url)->put(route('admin.cursos.publicacion.update', $curso), $this->datos(['titulo' => '']) + ['_revision' => $vieja])->assertSessionHasErrors('titulo');
        $editor = $this->get($url)->assertOk();
        $this->assertStringContainsString('value="'.e($vieja).'"', $editor->getContent());
        $this->assertNotSame($vieja, $editor->headers->get('ETag'));
    }

    private function revision(Curso $curso): string
    {
        return $this->get(route('admin.cursos.publicacion.edit', $curso))->assertOk()->headers->get('ETag');
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
