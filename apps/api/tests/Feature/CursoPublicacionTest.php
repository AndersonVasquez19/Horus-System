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
use Tests\Support\LlamaCursosDelPanel;
use Tests\Concerns\ChatPublicoBff;
use Tests\Concerns\CursosPublicosBff;
use Tests\TestCase;

class CursoPublicacionTest extends TestCase
{
    use LlamaCursosDelPanel;
    use CursosPublicosBff;
    use RefreshDatabase;
    use ChatPublicoBff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'America/Lima'));
        // Este contrato histórico aísla la publicación editorial de su visualización web.
        config(['catalogo-publico.enabled' => false]);
        // La integración dinámica se prueba de forma explícita; queda apagada por defecto.
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => 'fake-test-key', 'chatbot.public_catalog_enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_publication_requires_explicit_content_and_does_not_copy_admin_data(): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser());
        // El editor del panel nombra el curso que se edita, pero la ficha parte vacía: no copia datos administrativos.
        $this->fichaCursoPanel($course)->assertOk()->assertJsonPath('data.ficha', null)->assertJsonPath('data.publicable', true);
        $this->assertStringNotContainsString('Nombre interno secreto', $this->textoCursosWeb());
        $this->publicarFichaCursoPanel($course, $this->data() + ['precio' => 1, 'estado' => 'inactivo', 'autorizado_por' => 999, 'publicada' => false])->assertOk();
        $publication = CursoPublicacion::firstOrFail();
        $this->assertTrue($publication->publicada);
        $this->assertSame(auth()->id(), $publication->autorizado_por);
        $this->assertSame('activo', $course->fresh()->estado);
        $this->assertSame('9876.54', $course->fresh()->precio);
        $web = $this->textoCursosWeb();
        foreach (['Python inicial', 'Nombre interno secreto', '9876.54', 'Horario privado', 'Descripción administrativa'] as $oculto) {
            $this->assertStringNotContainsString($oculto, $web);
        }
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
            'expired' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-20', 'vigente_hasta' => '2026-10-27']),
            'future' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-29']),
        };
        $this->assertStringNotContainsString('Python inicial', $this->textoCursosWeb());
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Qué cursos ofrecen', 'hidden');
        $this->assertStringNotContainsString('Python inicial', $answer['respuesta']);
        $this->assertSame([], app(CatalogoPublicoCursos::class)->vigentes());
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
    public function test_published_facts_remain_in_editorial_reader_without_entering_chat(string $question, string $expected): void
    {
        $this->publish(['horario' => 'Martes de 19 a 21 horas', 'inscripcion' => 'Solicita orientación en contactos.']);
        $answer = app(AsistenteVirtualService::class)->procesarMensaje($question, 'dynamic');
        $this->assertSame('respaldo', $answer['fuente']);
        $this->assertStringContainsString($expected, json_encode(app(CatalogoPublicoCursos::class)->vigentes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
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
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_titles_ask_for_clarification_and_full_title_resolves(): void
    {
        $this->publish();
        $this->publish(['titulo' => 'Python avanzado']);
        $service = app(AsistenteVirtualService::class);
        $answer = $service->procesarMensaje('Horario del curso Python', 'ambiguous');
        $this->assertSame('respaldo', $answer['fuente']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertSame('respaldo', $service->procesarMensaje('Horario del curso Python avanzado', 'specific')['fuente']);
        $this->assertSame('respaldo', $service->procesarMensaje('Precio y horario del curso Python inicial', 'compound')['fuente']);
        Http::assertNothingSent();
    }

    public function test_publication_updates_do_not_enter_public_chat_cache(): void
    {
        $course = $this->publish(['horario' => 'Horario oficial anterior']);
        $service = app(AsistenteVirtualService::class);
        $question = 'Horario del curso Python inicial';
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->publicarFichaCursoPanel($course, array_replace($this->data(), ['horario' => 'Horario oficial actualizado']))->assertOk();
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->retirarFichaCursoPanel($course)->assertOk();
        $this->assertStringNotContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'cache')['respuesta']);
        $this->publicarFichaCursoPanel($course, array_replace($this->data(), ['horario' => 'Horario oficial actualizado', 'vigente_hasta' => '2026-10-28']))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-28 23:59:50', 'America/Lima'));
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->travel(11)->seconds();
        $this->assertStringNotContainsString('Horario oficial actualizado', $service->procesarMensaje($question, 'midnight')['respuesta']);
        $this->assertStringNotContainsString('Python inicial', $this->textoCursosWeb());
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
        $this->actingAs($user)->fichaCursoPanel($course)->assertForbidden();
        $this->publicarFichaCursoPanel($course, $this->data())->assertForbidden();
        $this->retirarFichaCursoPanel($course)->assertForbidden();
        $this->assertTrue(CursoPublicacion::firstOrFail()->publicada);
    }

    public function test_permission_can_be_explicitly_granted_and_unpublication_works_for_deleted_courses(): void
    {
        $course = $this->publish();
        $user = User::factory()->create();
        $user->assignRole('coordinador');
        $user->givePermissionTo('cursos.publicar');
        $this->actingAs($user)->publicarFichaCursoPanel($course, $this->data())->assertOk();
        $course->delete();
        $this->fichaCursoPanel($course)->assertOk();
        $this->retirarFichaCursoPanel($course)->assertOk();
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
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($course, array_replace($this->data(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field === 'vigente_desde' ? 'vigente_hasta' : $field);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public static function fechasDeInicioNoFuturas(): iterable
    {
        yield 'hoy' => ['2026-10-28'];
        yield 'ayer' => ['2026-10-27'];
        yield 'hace meses' => ['2026-03-01'];
    }

    /** El reloj de la prueba marca el 28/10/2026 en Lima (25/11/2026: no se publican cursos con inicio pasado). */
    #[DataProvider('fechasDeInicioNoFuturas')]
    public function test_la_fecha_de_inicio_debe_ser_posterior_a_hoy(string $fecha): void
    {
        $course = $this->course();

        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($course, [...$this->data(), 'fecha_inicio' => $fecha])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fecha_inicio' => 'La fecha de inicio debe ser posterior a hoy. Si el curso ya empezó, deja el campo vacío o indica la próxima fecha.']);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_acepta_una_fecha_de_inicio_futura_o_ninguna_y_cuenta_el_dia_de_lima(): void
    {
        // En UTC ya es 29/10; en Lima sigue siendo 28/10, así que el 29 es «mañana».
        $this->travelTo(Carbon::parse('2026-10-28 23:59:30', 'America/Lima'));
        $course = $this->course();
        $this->actingAs($this->adminUser());

        $this->publicarFichaCursoPanel($course, [...$this->data(), 'fecha_inicio' => '2026-10-29'])->assertOk();
        $this->assertSame('2026-10-29', CursoPublicacion::firstOrFail()->fecha_inicio?->format('Y-m-d'));
        $this->publicarFichaCursoPanel($course, [...$this->data(), 'fecha_inicio' => null])->assertOk();
        $this->assertNull(CursoPublicacion::firstOrFail()->fecha_inicio);
    }

    public function test_al_editar_una_ficha_cuyo_curso_ya_empezo_hay_que_cambiar_o_vaciar_la_fecha(): void
    {
        $course = $this->publish(['fecha_inicio' => '2026-10-29']);
        // El curso ya empezó: la fecha guardada quedó en el pasado.
        CursoPublicacion::query()->update(['fecha_inicio' => '2026-10-27']);
        $cambio = [...$this->data(), 'horario' => 'Horario nuevo', 'fecha_inicio' => '2026-10-27'];

        $this->publicarFichaCursoPanel($course, $cambio)->assertUnprocessable()->assertJsonValidationErrors('fecha_inicio');
        $this->assertNull(CursoPublicacion::firstOrFail()->horario);
        $this->publicarFichaCursoPanel($course, [...$cambio, 'fecha_inicio' => ''])->assertOk();
        $this->assertSame('Horario nuevo', CursoPublicacion::firstOrFail()->horario);
    }

    /** Lo que el exportador del asistente rechazaría después (y dejaría sin catálogo a todos) se rechaza al guardar. */
    public static function textoQueBloqueariaElCatalogo(): iterable
    {
        yield 'correo en el resumen' => ['resumen', 'Escribe a ana@example.invalid para más detalles'];
        yield 'telefono en el resumen' => ['resumen', 'Llama al 972 021 374 para informes'];
        yield 'dni en el resumen' => ['resumen', 'Documento personal 12345678 requerido'];
        yield 'palabra administrativa' => ['resumen', 'Curso para estudiantes de primer ciclo'];
        yield 'palabra administrativa en el horario' => ['horario', 'Solo clientes inscritos'];
        yield 'telefono en la inscripcion' => ['inscripcion', 'Separa tu cupo al 987654321 hoy'];
        yield 'correo en el temario' => ['temario', 'Consultas: docente@example.invalid'];
    }

    #[DataProvider('textoQueBloqueariaElCatalogo')]
    public function test_text_the_assistant_catalog_would_reject_is_refused_when_saving(string $field, string $value): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($course, array_replace($this->data(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_number_split_across_fields_is_also_refused_like_the_exporter_does(): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($course, array_replace($this->data(), [
            'resumen' => 'Informes al 972 021', 'horario' => '374 de lunes a viernes',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('curso_publicaciones', 0);
    }

    public function test_normal_editorial_text_with_digits_and_dates_still_publishes(): void
    {
        $this->publish(['resumen' => 'Aprende comunicación efectiva en 2 semanas', 'horario' => 'Lunes a viernes de 2 pm a 5 pm', 'duracion' => '2 semanas']);
        $this->assertDatabaseCount('curso_publicaciones', 1);
    }

    public function test_late_inactivation_is_rechecked_and_write_failure_rolls_back_publication_and_audit(): void
    {
        $course = $this->course();
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(PublicarCursoRequest::class, fn () => $course->update(['estado' => 'inactivo']));
        $this->publicarFichaCursoPanel($course, $this->data())->assertUnprocessable();
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
            $this->publicarFichaCursoPanel($course, array_replace($this->data(), ['titulo' => 'Cambio fallido']));
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Failure after publication write', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public function test_scheduled_publication_does_not_activate_chat_catalog(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 23:59:50', 'America/Lima'));
        $this->publish();
        $service = app(AsistenteVirtualService::class);
        $this->assertStringNotContainsString('Python inicial', $service->procesarMensaje('cursos', 'scheduled')['respuesta']);
        $this->travel(11)->seconds();
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
        $this->assertStringNotContainsString('Python inicial', $this->textoCursosWeb());
        Http::assertNothingSent();
    }

    public function test_inactivation_and_deletion_hide_previously_cached_public_answers(): void
    {
        $course = $this->publish();
        $service = app(AsistenteVirtualService::class);
        foreach (['inactivo', 'archivado', 'deleted'] as $condition) {
            $course->update(['estado' => 'activo']);
            $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('cursos', 'catalog-independent')['respuesta']);
            $condition === 'deleted' ? $course->delete() : $course->update(['estado' => $condition]);
            $this->assertStringNotContainsString('Python inicial', $service->procesarMensaje('cursos', 'state-'.$condition)['respuesta']);
        }
        Http::assertNothingSent();
    }

    public function test_editorial_content_is_escaped_in_the_editor_and_does_not_modify_the_public_page(): void
    {
        // El HTML se rechaza al guardar (el catálogo del asistente lo descartaría entero).
        $rechazado = $this->course();
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($rechazado, array_replace($this->data(), ['resumen' => '<script>alert("private")</script>']))
            ->assertUnprocessable()->assertJsonValidationErrors('resumen');

        // Los caracteres que sí se aceptan (comillas, &) se entregan literales; quien los muestra, la UI de Next, los escapa.
        $texto = 'Redes & "seguridad" básica con prácticas guiadas';
        $course = $this->publish(['resumen' => $texto]);
        $this->fichaCursoPanel($course)->assertOk()->assertJsonPath('data.ficha.resumen', $texto);
        $this->assertStringNotContainsString('Python inicial', $this->textoCursosWeb());
        $this->assertStringNotContainsString('<script>', $this->textoCursosWeb());
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Compara modalidades de cursos', 'local-comparison');
        $this->assertSame('respaldo', $answer['fuente']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
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

    public function test_catalog_flag_does_not_enable_php_conversation_or_change_public_page(): void
    {
        $this->publish(['horario' => 'Miércoles de 18 a 20 horas']);
        $this->postChatPublico(['mensaje' => 'Horario del curso Python inicial', 'session_id' => 'public-course'])
            ->assertOk()->assertJsonPath('fuente', 'respaldo')
            ->assertJsonPath('respuesta', AsistenteVirtualService::RESPUESTA_FALLBACK)
            ->assertDontSee('Horario privado');
        $this->assertStringNotContainsString('Miércoles de 18 a 20 horas', $this->textoCursosWeb());
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
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertStringNotContainsString('curso_publicaciones', implode(' ', $queries));
        $this->assertStringNotContainsString('Python inicial', $answer['respuesta']);
        $this->assertStringNotContainsString('Python inicial', $this->textoCursosWeb());
        $this->assertStringNotContainsString('Horario editorial reservado', $this->textoCursosWeb());
        Http::assertNothingSent();
    }

    public function test_array_shaped_input_is_rejected_and_anonymous_writes_are_rejected(): void
    {
        $course = $this->course();
        $this->publicarFichaCursoPanel($course, $this->data())->assertUnauthorized();
        // Un `titulo` con forma de array no se interpreta como contenido: se rechaza con 422 y no escribe nada.
        $this->actingAs($this->adminUser());
        $this->publicarFichaCursoPanel($course, ['titulo' => ['array'], 'vigente_desde' => ['array']] + $this->data())
            ->assertUnprocessable()->assertJsonValidationErrors(['titulo', 'vigente_desde']);
        $this->assertDatabaseCount('curso_publicaciones', 0);
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
        $this->actingAs($admin)->publicarFichaCursoPanel($course, array_replace($this->data(), $overrides))->assertOk();

        return $course;
    }
}
