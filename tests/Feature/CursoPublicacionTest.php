<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\PublicarCursoRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use App\Services\CatalogoPublicoCursos;
use App\Support\ChatKnowledge;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CursoPublicacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'America/Lima'));
        // La integración dinámica se prueba de forma explícita; queda apagada por defecto.
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => 'fake-test-key', 'chatbot.public_catalog_enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_publication_requires_explicit_content_and_does_not_copy_admin_data(): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser());
        $this->get(route('admin.cursos.publicacion.edit', $course))->assertOk()->assertDontSee('Nombre interno secreto');
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Nombre interno secreto');
        $this->putJson(route('admin.cursos.publicacion.update', $course), $this->data() + ['precio' => 1, 'estado' => 'inactivo', 'autorizado_por' => 999, 'publicada' => false])->assertRedirect();
        $publication = CursoPublicacion::firstOrFail();
        $this->assertTrue($publication->publicada);
        $this->assertSame(auth()->id(), $publication->autorizado_por);
        $this->assertSame('activo', $course->fresh()->estado);
        $this->assertSame('9876.54', $course->fresh()->precio);
        $this->get(route('educacion.cursos'))->assertOk()->assertSee('Cursos Disponibles.')->assertDontSee('Python inicial')
            ->assertDontSee('Nombre interno secreto')->assertDontSee('9876.54')->assertDontSee('Horario privado')->assertDontSee('Descripción administrativa');
        $row = app(CatalogoPublicoCursos::class)->vigentes()[0];
        $this->assertSame('Python inicial', $row['titulo']);
        $this->assertSame(['titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_desde', 'vigente_hasta'], array_keys($row));
        $this->assertDatabaseHas('audit_logs', ['action' => 'curso_publicado', 'model_id' => $course->id]);
    }

    public static function hiddenConditions(): iterable
    {
        foreach (['unpublished', 'inactive', 'archived', 'deleted', 'expired', 'future'] as $condition) {
            yield $condition => [$condition];
        }
    }

    #[DataProvider('hiddenConditions')]
    public function test_nonpublic_or_invalid_publications_are_hidden_from_web_and_chat(string $condition): void
    {
        $course = $this->publish();
        match ($condition) {
            'unpublished' => CursoPublicacion::query()->update(['publicada' => false]),
            'inactive' => $course->update(['estado' => 'inactivo']),
            'archived' => $course->update(['estado' => 'archivado']),
            'deleted' => $course->delete(),
            'expired' => CursoPublicacion::query()->update(['vigente_hasta' => '2026-10-27']),
            'future' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-29']),
        };
        $this->get(route('educacion.cursos'))->assertOk()->assertDontSee('Python inicial');
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Qué cursos ofrecen', 'hidden');
        $this->assertStringNotContainsString('Python inicial', $answer['respuesta']);
        $this->assertStringContainsString('No hay cursos', $answer['respuesta']);
        Http::assertNothingSent();
    }

    public static function localCourseQuestions(): iterable
    {
        yield 'list' => ['Qué cursos están publicados', 'Python inicial'];
        yield 'modality' => ['Qué modalidad tiene Python inicial', 'virtual'];
        yield 'schedule' => ['Horario del curso Python inicial', 'Martes de 19 a 21 horas'];
        yield 'enrollment' => ['Cómo inscribirme en Python inicial', 'Solicita orientación en contactos.'];
        yield 'requirements' => ['Requisitos del curso Python inicial', 'Solicita orientación en contactos.'];
        yield 'title only' => ['Python inicial', 'Resumen autorizado'];
    }

    #[DataProvider('localCourseQuestions')]
    public function test_published_course_facts_are_local_and_do_not_call_openai(string $question, string $expected): void
    {
        $this->publish(['horario' => 'Martes de 19 a 21 horas', 'inscripcion' => 'Solicita orientación en contactos.']);
        $answer = app(AsistenteVirtualService::class)->procesarMensaje($question, 'dynamic');
        $this->assertSame('local', $answer['fuente']);
        $this->assertStringContainsString($expected, $answer['respuesta']);
        $this->assertLessThanOrEqual(1800, mb_strlen($answer['respuesta']));
        foreach (['Nombre interno secreto', '9876.54', 'Horario privado', 'Descripción administrativa'] as $private) {
            $this->assertStringNotContainsString($private, $answer['respuesta']);
        }
        Http::assertNothingSent();
    }

    public function test_missing_schedule_or_inscription_and_unknown_course_are_explicit(): void
    {
        $this->publish();
        $service = app(AsistenteVirtualService::class);
        $this->assertStringContainsString('Horario no publicado', $service->procesarMensaje('Horario del curso Python inicial', 'missing-schedule')['respuesta']);
        $this->assertStringContainsString('inscripción no publicada', $service->procesarMensaje('Inscripción del curso Python inicial', 'missing-enrollment')['respuesta']);
        $this->assertStringContainsString('No encuentro una ficha pública', $service->procesarMensaje('Horario del curso Robótica', 'unknown')['respuesta']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_titles_ask_for_clarification_and_full_title_resolves(): void
    {
        $this->publish();
        $this->publish(['titulo' => 'Python avanzado']);
        $service = app(AsistenteVirtualService::class);
        $answer = $service->procesarMensaje('Horario del curso Python', 'ambiguous');
        $this->assertSame('aclaracion', $answer['fuente']);
        $this->assertStringContainsString('título completo', $answer['respuesta']);
        $this->assertSame('local', $service->procesarMensaje('Horario del curso Python avanzado', 'specific')['fuente']);
        $this->assertSame('aclaracion', $service->procesarMensaje('Precio y horario del curso Python inicial', 'compound')['fuente']);
        Http::assertNothingSent();
    }

    public function test_changes_unpublication_and_expiry_invalidate_duplicate_cache_immediately(): void
    {
        $course = $this->publish(['horario' => 'Horario oficial anterior']);
        $service = app(AsistenteVirtualService::class);
        $question = 'Horario del curso Python inicial';
        $this->assertStringContainsString('Horario oficial anterior', $service->procesarMensaje($question, 'cache')['respuesta']);
        $this->putJson(route('admin.cursos.publicacion.update', $course), array_replace($this->data(), ['horario' => 'Horario oficial actualizado']))->assertRedirect();
        $this->assertStringContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'cache')['respuesta']);
        $this->deleteJson(route('admin.cursos.publicacion.destroy', $course))->assertRedirect();
        $this->assertStringNotContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'cache')['respuesta']);
        $this->putJson(route('admin.cursos.publicacion.update', $course), array_replace($this->data(), ['horario' => 'Horario oficial actualizado', 'vigente_hasta' => '2026-10-28']))->assertRedirect();
        $this->travelTo(Carbon::parse('2026-10-28 23:59:50', 'America/Lima'));
        $this->assertStringContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'midnight')['respuesta']);
        $this->travel(11)->seconds();
        $this->assertStringNotContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'midnight')['respuesta']);
        $this->get(route('educacion.cursos'))->assertDontSee('Python inicial');
        Http::assertNothingSent();
    }

    public static function staffWithoutPublicationPermission(): iterable
    {
        yield 'coordinator' => ['coordinador'];
        yield 'secretary' => ['secretaria'];
    }

    #[DataProvider('staffWithoutPublicationPermission')]
    public function test_editor_or_reader_cannot_publish_or_unpublish(string $role): void
    {
        $course = $this->publish();
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user)->get(route('admin.cursos.publicacion.edit', $course))->assertForbidden();
        $this->putJson(route('admin.cursos.publicacion.update', $course), $this->data())->assertForbidden();
        $this->deleteJson(route('admin.cursos.publicacion.destroy', $course))->assertForbidden();
        $this->assertTrue(CursoPublicacion::firstOrFail()->publicada);
    }

    public function test_permission_can_be_explicitly_granted_and_unpublication_works_for_deleted_courses(): void
    {
        $course = $this->publish();
        $user = User::factory()->create();
        $user->assignRole('coordinador');
        $user->givePermissionTo('cursos.publicar');
        $this->actingAs($user)->putJson(route('admin.cursos.publicacion.update', $course), $this->data())->assertRedirect();
        $course->delete();
        $this->get(route('admin.cursos.publicacion.edit', $course))->assertOk();
        $this->deleteJson(route('admin.cursos.publicacion.destroy', $course))->assertRedirect();
        $this->assertFalse(CursoPublicacion::firstOrFail()->publicada);
        $this->assertSoftDeleted($course);
    }

    public static function invalidPublicationData(): iterable
    {
        yield 'missing consent' => ['confirmacion_publica', null];
        yield 'empty title' => ['titulo', ''];
        yield 'missing expiry' => ['vigente_hasta', null];
        yield 'expired' => ['vigente_hasta', '2026-10-27'];
        yield 'inverted dates' => ['vigente_desde', '2026-12-01'];
        yield 'unknown modality' => ['modalidad', 'interno'];
        yield 'array input' => ['horario', ['secreto']];
    }

    #[DataProvider('invalidPublicationData')]
    public function test_invalid_editorial_data_never_publishes(string $field, mixed $value): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser())->putJson(route('admin.cursos.publicacion.update', $course), array_replace($this->data(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field === 'vigente_desde' ? 'vigente_hasta' : $field);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_late_inactivation_is_rechecked_and_write_failure_rolls_back_publication_and_audit(): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(PublicarCursoRequest::class, fn () => $course->update(['estado' => 'inactivo']));
        $this->putJson(route('admin.cursos.publicacion.update', $course), $this->data())->assertUnprocessable();
        $this->assertDatabaseCount('curso_publicaciones', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'curso_publicado']);
    }

    public function test_exception_rolls_back_editorial_content_and_authorization(): void
    {
        $course = $this->publish();
        $before = CursoPublicacion::firstOrFail()->getAttributes();
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('Failure after publication write');
        });
        $this->withoutExceptionHandling();
        try {
            $this->putJson(route('admin.cursos.publicacion.update', $course), array_replace($this->data(), ['titulo' => 'Cambio fallido']));
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Failure after publication write', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public function test_scheduled_publication_becomes_visible_without_reusing_a_cached_negative_answer(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 23:59:50', 'America/Lima'));
        $this->publish();
        $service = app(AsistenteVirtualService::class);
        $this->assertStringNotContainsString('Python inicial', $service->procesarMensaje('cursos', 'scheduled')['respuesta']);
        $this->travel(11)->seconds();
        $this->assertStringContainsString('Python inicial', $service->procesarMensaje('cursos', 'scheduled')['respuesta']);
        $this->get(route('educacion.cursos'))->assertDontSee('Python inicial');
        Http::assertNothingSent();
    }

    public function test_inactivation_and_deletion_hide_previously_cached_public_answers(): void
    {
        $course = $this->publish();
        $service = app(AsistenteVirtualService::class);
        foreach (['inactivo', 'archivado', 'deleted'] as $condition) {
            $course->update(['estado' => 'activo']);
            $this->assertStringContainsString('Python inicial', $service->procesarMensaje('cursos', 'state-'.$condition)['respuesta']);
            $condition === 'deleted' ? $course->delete() : $course->update(['estado' => $condition]);
            $this->assertStringNotContainsString('Python inicial', $service->procesarMensaje('cursos', 'state-'.$condition)['respuesta']);
        }
        Http::assertNothingSent();
    }

    public function test_editorial_content_is_escaped_in_the_editor_and_does_not_modify_the_public_page(): void
    {
        $course = $this->publish(['resumen' => '<script>alert("private")</script>']);
        $this->get(route('admin.cursos.publicacion.edit', $course))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("private")</script>', false);
        $this->get(route('educacion.cursos'))->assertDontSee('Python inicial')->assertDontSee('&lt;script&gt;', false);
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Compara modalidades de cursos', 'local-comparison');
        $this->assertSame('local', $answer['fuente']);
        $this->assertStringContainsString('virtual', $answer['respuesta']);
        Http::assertNothingSent();
    }

    public function test_existing_installation_migration_assigns_publication_only_to_admin(): void
    {
        $this->adminUser();
        $migration = require database_path('migrations/2026_10_28_000021_create_curso_publicaciones_table.php');
        $migration->down();
        $migration->up();
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('cursos.publicar'));
        $this->assertFalse(Role::findByName('coordinador')->hasPermissionTo('cursos.publicar'));
        $this->assertFalse(Role::findByName('secretaria')->hasPermissionTo('cursos.publicar'));
    }

    public function test_explicitly_enabled_chat_can_resolve_authorized_facts_without_changing_the_public_page(): void
    {
        $this->publish(['horario' => 'Miércoles de 18 a 20 horas']);
        $this->postJson(route('chat.mensaje'), ['mensaje' => 'Horario del curso Python inicial', 'session_id' => 'public-course'])
            ->assertOk()->assertJsonPath('fuente', 'local')->assertJsonPath('intent', 'consulta_horarios_cursos')
            ->assertJsonPath('respuesta', fn (string $answer): bool => str_contains($answer, 'Miércoles de 18 a 20 horas'))
            ->assertDontSee('Horario privado');
        $this->get(route('educacion.cursos'))->assertDontSee('Miércoles de 18 a 20 horas')->assertSee('Cursos Disponibles.');
        Http::assertNothingSent();
    }

    public function test_public_catalog_is_disabled_by_default_and_does_not_query_editorial_data(): void
    {
        $this->publish(['horario' => 'Horario editorial reservado']);
        $default = require config_path('chatbot.php');
        $this->assertFalse($default['public_catalog_enabled']);
        config(['chatbot.public_catalog_enabled' => false]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('cursos', 'default-static');
        $original = collect(ChatKnowledge::intents())->firstWhere('nombre', 'consulta_cursos');
        $this->assertSame($original['respuesta'], $answer['respuesta']);
        $this->assertStringNotContainsString('curso_publicaciones', implode(' ', $queries));
        $this->assertStringNotContainsString('Python inicial', $answer['respuesta']);
        $this->get(route('educacion.cursos'))->assertSee('Horarios Adaptables.')->assertSee('Cursos Disponibles.')
            ->assertDontSee('Python inicial')->assertDontSee('Horario editorial reservado');
        Http::assertNothingSent();
    }

    public function test_malformed_old_input_does_not_break_the_editor_and_anonymous_writes_are_rejected(): void
    {
        $course = $this->course();
        $this->putJson(route('admin.cursos.publicacion.update', $course), $this->data())->assertUnauthorized();
        $this->actingAs($this->adminUser())->withSession(['_old_input' => ['titulo' => ['array'], 'vigente_desde' => ['array']]])
            ->get(route('admin.cursos.publicacion.edit', $course))->assertOk()->assertSee('confirmacion_publica');
    }

    private function course(): Curso
    {
        return Curso::create([
            'codigo' => 'PRIVATE-'.Curso::withTrashed()->count(), 'nombre' => 'Nombre interno secreto',
            'descripcion' => 'Descripción administrativa', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
            'precio' => 9876.54, 'modalidad' => 'presencial', 'horario' => 'Horario privado',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);
    }

    private function data(): array
    {
        return ['titulo' => 'Python inicial', 'resumen' => 'Resumen autorizado', 'modalidad' => 'virtual', 'horario' => null, 'inscripcion' => null,
            'vigente_desde' => '2026-10-28', 'vigente_hasta' => '2026-11-28', 'confirmacion_publica' => '1'];
    }

    private function publish(array $overrides = []): Curso
    {
        $course = $this->course();
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first() ?? $this->adminUser();
        $this->actingAs($admin)->putJson(route('admin.cursos.publicacion.update', $course), array_replace($this->data(), $overrides))->assertRedirect();

        return $course;
    }
}
