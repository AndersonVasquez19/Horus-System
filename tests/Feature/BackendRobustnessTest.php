<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackendRobustnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public static function changedParents(): iterable
    {
        yield 'inactive student' => ['estudiante', 'inactivo'];
        yield 'deleted student' => ['estudiante', 'deleted'];
        yield 'inactive course' => ['curso', 'inactivo'];
        yield 'archived course' => ['curso', 'archivado'];
        yield 'deleted course' => ['curso', 'deleted'];
    }

    #[DataProvider('changedParents')]
    public function test_creation_rechecks_eligibility_after_request_validation(string $parent, string $change): void
    {
        [$student, $course, $data] = $this->fixtures();
        $this->actingAs($this->adminUser());
        // Deterministic interleaving, not a second connection or PostgreSQL lock test.
        $this->app->afterResolving(StoreMatriculaRequest::class, function (StoreMatriculaRequest $request) use ($parent, $change, $student, $course): void {
            $this->assertSame($student->id, (int) $request->validated('estudiante_id'));
            $model = $parent === 'estudiante' ? $student : $course;
            $change === 'deleted' ? $model->delete() : $model->update(['estado' => $change]);
        });

        $this->postJson(route('admin.matriculas.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($parent.'_id');

        $this->assertDatabaseCount('matriculas', 0);
        $this->assertDatabaseCount('matricula_notifications', 0);
        $this->assertSame(2, $course->fresh()->cupos_disponibles);
        Http::assertNothingSent();
    }

    public static function deletedParents(): iterable
    {
        yield 'student' => ['estudiante'];
        yield 'course' => ['curso'];
    }

    #[DataProvider('deletedParents')]
    public function test_update_rechecks_parent_deletion_after_validation(string $parent): void
    {
        [$student, $course, $data] = $this->fixtures();
        $enrollment = $this->enroll($data, $course);
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(UpdateMatriculaRequest::class, function (UpdateMatriculaRequest $request) use ($parent, $student, $course): void {
            $this->assertSame('pagada', $request->validated('estado'));
            ($parent === 'estudiante' ? $student : $course)->delete();
        });

        $this->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['estado' => 'pagada']))
            ->assertUnprocessable()->assertJsonValidationErrors($parent.'_id');

        $this->assertSame('pendiente', $enrollment->fresh()->estado);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);
    }

    public function test_editor_cannot_bypass_annul_permission_via_update(): void
    {
        [$student, $course, $data] = $this->fixtures();
        $enrollment = $this->enroll($data, $course);
        $this->adminUser();
        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $this->assertTrue($editor->can('matriculas.editar'));
        $this->assertFalse($editor->can('matriculas.anular'));

        $this->actingAs($editor)->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['estado' => 'anulada']))
            ->assertForbidden();
        $this->patchJson(route('admin.matriculas.anular', $enrollment))->assertForbidden();
        $this->assertSame('pendiente', $enrollment->fresh()->estado);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);

        $this->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['estado' => 'pagada']))
            ->assertRedirect();
        $this->assertSame('pagada', $enrollment->fresh()->estado);
    }

    public function test_authorized_annul_via_update_releases_seat_once(): void
    {
        [, $course, $data] = $this->fixtures();
        $enrollment = $this->enroll($data, $course);
        $this->actingAs($this->adminUser());
        foreach ([1, 2] as $attempt) {
            $this->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['estado' => 'anulada']))
                ->assertRedirect();
            $this->assertSame(2, $course->fresh()->cupos_disponibles);
        }
        $this->assertDatabaseCount('matricula_notifications', 1);
    }

    public static function oversizedCourseNumbers(): iterable
    {
        yield 'price' => ['precio', '100000000'];
        yield 'price exponent' => ['precio', '1e100'];
        yield 'duration' => ['duracion_horas', '2147483648'];
        yield 'capacity' => ['cupos_maximos', '2147483648'];
    }

    #[DataProvider('oversizedCourseNumbers')]
    public function test_course_numbers_fit_database_columns_on_create_and_update(string $field, string $value): void
    {
        [, $course] = $this->fixtures();
        $data = $course->only(['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado']);
        $original = $course->getRawOriginal($field);
        $this->actingAs($this->adminUser());

        $this->postJson(route('admin.cursos.store'), array_replace($data, ['codigo' => 'NEW', $field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson(route('admin.cursos.update', $course), array_replace($data, [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('cursos', 1);
        $this->assertSame($original, $course->fresh()->getRawOriginal($field));
    }

    public function test_course_numeric_boundaries_are_accepted(): void
    {
        [, $course] = $this->fixtures();
        $data = $course->only(['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado']);
        $data = array_replace($data, ['codigo' => 'LIMIT', 'precio' => '99999999.99', 'duracion_horas' => 2147483647, 'cupos_maximos' => 2147483647]);
        $this->actingAs($this->adminUser())->postJson(route('admin.cursos.store'), $data)->assertRedirect();
        $created = Curso::where('codigo', 'LIMIT')->sole();
        $this->assertSame('99999999.99', $created->precio);
        $this->assertSame(2147483647, $created->cupos_disponibles);
        $this->putJson(route('admin.cursos.update', $created), array_replace($data, ['precio' => 0, 'duracion_horas' => 1, 'cupos_maximos' => 1]))->assertRedirect();
        $this->assertSame('0.00', $created->fresh()->precio);
        $this->assertSame(1, $created->fresh()->cupos_disponibles);
    }

    public function test_duplicate_created_after_validation_does_not_consume_another_seat(): void
    {
        [, $course, $data] = $this->fixtures();
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(StoreMatriculaRequest::class, function (StoreMatriculaRequest $request) use ($course): void {
            $this->enroll($request->validated(), $course);
        });
        $this->postJson(route('admin.matriculas.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('estudiante_id');
        $this->assertDatabaseCount('matriculas', 1);
        $this->assertDatabaseCount('matricula_notifications', 1);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);
    }

    public function test_last_seat_taken_after_validation_is_rejected(): void
    {
        [, $course, $data] = $this->fixtures();
        $course->update(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $other = Estudiante::create(['dni' => '70000002', 'nombres' => 'Otra', 'apellidos' => 'Persona', 'estado' => 'activo']);
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(StoreMatriculaRequest::class, function (StoreMatriculaRequest $request) use ($course, $other): void {
            $this->enroll(array_replace($request->validated(), ['estudiante_id' => $other->id]), $course);
        });
        $this->postJson(route('admin.matriculas.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('curso_id');
        $this->assertDatabaseCount('matriculas', 1);
        $this->assertSame(0, $course->fresh()->cupos_disponibles);
    }

    public function test_creation_failure_rolls_back_enrollment_seat_and_notification(): void
    {
        [, $course, $data] = $this->fixtures();
        $this->actingAs($this->adminUser())->withoutExceptionHandling();
        Event::listen('eloquent.created: '.Matricula::class, function (): void {
            throw new RuntimeException('simulated persistence failure');
        });
        try {
            $this->postJson(route('admin.matriculas.store'), $data);
            $this->fail('Expected persistence failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated persistence failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertDatabaseCount('matricula_notifications', 0);
        $this->assertSame(2, $course->fresh()->cupos_disponibles);
        Http::assertNothingSent();
    }

    public function test_transfer_failure_rolls_back_both_course_counters(): void
    {
        [, $course, $data] = $this->fixtures();
        $enrollment = $this->enroll($data, $course);
        $destination = $course->replicate();
        $destination->codigo = 'DEST';
        $destination->cupos_disponibles = 2;
        $destination->save();
        $this->actingAs($this->adminUser())->withoutExceptionHandling();
        Event::listen('eloquent.updating: '.Matricula::class, function (): void {
            throw new RuntimeException('simulated update failure');
        });
        try {
            $this->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['curso_id' => $destination->id]));
            $this->fail('Expected persistence failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated update failure', $exception->getMessage());
        }
        $this->assertSame($course->id, $enrollment->fresh()->curso_id);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);
        $this->assertSame(2, $destination->fresh()->cupos_disponibles);
        $this->assertDatabaseCount('matricula_notifications', 1);
    }

    public function test_annul_permission_uses_current_state_instead_of_stale_route_binding(): void
    {
        [, $course, $data] = $this->fixtures();
        $enrollment = Matricula::create(array_replace($data, ['estado' => 'anulada']));
        $this->adminUser();
        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $this->actingAs($editor);
        $this->app->afterResolving(UpdateMatriculaRequest::class, function (UpdateMatriculaRequest $request) use ($enrollment, $course): void {
            $this->assertSame('anulada', $request->route('matricula')->estado);
            DB::transaction(function () use ($enrollment, $course): void {
                $course->decrement('cupos_disponibles');
                $enrollment->update(['estado' => 'pendiente']);
            });
        });
        $this->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['estado' => 'anulada']))->assertForbidden();
        $this->assertSame('pendiente', $enrollment->fresh()->estado);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);
    }

    public function test_editing_existing_inactive_relations_keeps_existing_contract(): void
    {
        [$student, $course, $data] = $this->fixtures();
        $enrollment = $this->enroll($data, $course);
        $student->update(['estado' => 'inactivo']);
        $course->update(['estado' => 'archivado']);
        $this->actingAs($this->adminUser())
            ->putJson(route('admin.matriculas.update', $enrollment), array_replace($data, ['observaciones' => 'Corrección administrativa']))
            ->assertRedirect();
        $this->assertSame('Corrección administrativa', $enrollment->fresh()->observaciones);
        $this->assertSame(1, $course->fresh()->cupos_disponibles);
    }

    private function fixtures(): array
    {
        $student = Estudiante::create(['dni' => '70000001', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $course = Curso::create([
            'codigo' => 'ROBUST', 'nombre' => 'Curso ficticio', 'descripcion' => 'Prueba',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 100,
            'modalidad' => 'virtual', 'horario' => 'Prueba', 'cupos_maximos' => 2,
            'cupos_disponibles' => 2, 'estado' => 'activo',
        ]);

        return [$student, $course, ['estudiante_id' => $student->id, 'curso_id' => $course->id, 'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente']];
    }

    private function enroll(array $data, Curso $course): Matricula
    {
        return DB::transaction(function () use ($data, $course): Matricula {
            $course->decrement('cupos_disponibles');

            return Matricula::create($data);
        });
    }
}
