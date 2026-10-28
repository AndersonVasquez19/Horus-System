<?php

declare(strict_types=1);

namespace Tests\Feature;

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

class MatriculaOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    public static function transitions(): iterable
    {
        foreach (['pendiente', 'pagada', 'anulada'] as $from) {
            foreach (['pendiente', 'pagada', 'anulada'] as $to) {
                foreach ([false, true] as $transfer) {
                    yield "$from -> $to / transfer=".(int) $transfer => [$from, $to, $transfer];
                }
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_state_and_transfer_matrix_preserves_seats_on_repetition(string $from, string $to, bool $transfer): void
    {
        [$student, $source, $enrollment] = $this->enrollment($from);
        $target = $transfer ? $this->course('TARGET') : $source;
        $notifications = DB::table('matricula_notifications')->count();
        $this->actingAs($this->adminUser());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, $to))->assertRedirect();
            $this->assertSame($to, $enrollment->fresh()->estado);
            $this->assertSame($target->id, $enrollment->fresh()->curso_id);
            $this->assertSeats($source);
            $this->assertSeats($target);
            $this->assertSame($notifications, DB::table('matricula_notifications')->count());
        }
        Http::assertNothingSent();
    }

    public static function staleBindings(): iterable
    {
        yield 'already reactivated' => [false];
        yield 'already transferred' => [true];
    }

    #[DataProvider('staleBindings')]
    public function test_stale_binding_does_not_reject_an_edit_that_needs_no_new_seat(bool $transfer): void
    {
        [$student, $source, $enrollment] = $this->enrollment($transfer ? 'pendiente' : 'anulada');
        $target = $transfer ? $this->course('TARGET', 1) : $source;
        if (! $transfer) {
            $source->update(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        }
        $this->actingAs($this->adminUser());
        // Between route binding and validation, on ONE connection: no concurrency claim.
        $this->app->resolving(UpdateMatriculaRequest::class, function () use ($enrollment, $source, $target, $transfer): void {
            if ($transfer) {
                $source->increment('cupos_disponibles');
            }
            $target->decrement('cupos_disponibles');
            Matricula::whereKey($enrollment->id)->update(['estado' => 'pendiente', 'curso_id' => $target->id]);
        });
        $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pagada'))
            ->assertRedirect();
        $this->assertSame('pagada', $enrollment->fresh()->estado);
        $this->assertSeats($source);
        $this->assertSeats($target);
        $this->assertSame(0, $target->fresh()->cupos_disponibles);
    }

    public static function parentConditions(): iterable
    {
        yield 'active' => ['activo', 'activo', null];
        yield 'inactive student' => ['inactivo', 'activo', null];
        yield 'inactive course' => ['activo', 'inactivo', null];
        yield 'mixed inactive archived' => ['inactivo', 'archivado', null];
        yield 'deleted student' => ['activo', 'activo', 'student'];
        yield 'deleted course' => ['activo', 'activo', 'course'];
    }

    #[DataProvider('parentConditions')]
    public function test_transfer_and_reactivation_keep_existing_parent_eligibility(string $studentState, string $courseState, ?string $deleted): void
    {
        [$student, $source, $enrollment] = $this->enrollment('anulada');
        $target = $this->course('TARGET');
        $student->update(['estado' => $studentState]);
        $target->update(['estado' => $courseState]);
        if ($deleted !== null) {
            ($deleted === 'student' ? $student : $target)->delete();
        }
        $response = $this->actingAs($this->adminUser())->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pagada'));
        if ($deleted !== null) {
            $response->assertUnprocessable()->assertJsonValidationErrors($deleted === 'student' ? 'estudiante_id' : 'curso_id');
            $this->assertSame('anulada', $enrollment->fresh()->estado);
            $this->assertSame($source->id, $enrollment->fresh()->curso_id);
        } else {
            $response->assertRedirect();
            $this->assertSame('pagada', $enrollment->fresh()->estado);
            $this->assertSame($target->id, $enrollment->fresh()->curso_id);
        }
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public static function restorationConditions(): iterable
    {
        foreach (self::parentConditions() as $name => $condition) {
            foreach (['pendiente', 'pagada', 'anulada'] as $state) {
                yield "$name / $state" => [$state, ...$condition];
            }
        }
    }

    #[DataProvider('restorationConditions')]
    public function test_restore_preserves_state_and_current_parent_policy_and_cannot_repeat(string $state, string $studentState, string $courseState, ?string $deleted): void
    {
        [$student, $course, $enrollment] = $this->enrollment($state);
        $this->actingAs($this->adminUser())->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertNotFound();
        $student->update(['estado' => $studentState]);
        $course->update(['estado' => $courseState]);
        if ($deleted !== null) {
            ($deleted === 'student' ? $student : $course)->delete();
        }
        $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertRedirect();
        $this->assertSame($state, $enrollment->fresh()->estado);
        $this->assertNotSoftDeleted($enrollment);
        $this->assertSeats($course);
        $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertNotFound();
        $this->assertSeats($course);
        $this->assertDatabaseCount('matricula_notifications', $state === 'anulada' ? 0 : 1);
    }

    public static function rollbackOperations(): iterable
    {
        yield 'annul' => ['anular', 'updating'];
        yield 'delete' => ['destroy', 'deleting'];
        yield 'restore' => ['restaurar', 'restoring'];
        yield 'transfer and reactivate' => ['update', 'updating'];
    }

    #[DataProvider('rollbackOperations')]
    public function test_failure_rolls_back_state_relations_and_seats(string $operation, string $event): void
    {
        [$student, $source, $enrollment] = $this->enrollment($operation === 'update' ? 'anulada' : 'pagada');
        $target = $this->course('TARGET');
        $this->actingAs($this->adminUser());
        if ($operation === 'restaurar') {
            $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        }
        $before = DB::table('matriculas')->where('id', $enrollment->id)->first();
        $sourceSeats = $source->fresh()->cupos_disponibles;
        $targetSeats = $target->fresh()->cupos_disponibles;
        $notifications = DB::table('matricula_notifications')->count();
        $eventName = "eloquent.$event: ".Matricula::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Simulated write failure');
        });
        $this->withoutExceptionHandling();
        try {
            match ($operation) {
                'update' => $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pendiente')),
                'destroy' => $this->deleteJson(route('admin.matriculas.destroy', $enrollment)),
                default => $this->patchJson(route('admin.matriculas.'.$operation, $enrollment->id)),
            };
            $this->fail('Expected simulated failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated write failure', $error->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $this->assertEquals($before, DB::table('matriculas')->where('id', $enrollment->id)->first());
        $this->assertSame($sourceSeats, $source->fresh()->cupos_disponibles);
        $this->assertSame($targetSeats, $target->fresh()->cupos_disponibles);
        $this->assertSeats($source);
        $this->assertSeats($target);
        $this->assertDatabaseCount('matricula_notifications', $notifications);
    }

    public function test_mixed_transfer_and_annul_cannot_bypass_permission(): void
    {
        [$student, $source, $enrollment] = $this->enrollment('pagada');
        $target = $this->course('TARGET');
        $this->adminUser();
        $editor = User::factory()->create();
        $editor->assignRole('coordinador');
        $this->actingAs($editor)->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'anulada'))->assertForbidden();
        $this->assertSame($source->id, $enrollment->fresh()->curso_id);
        $this->assertSame('pagada', $enrollment->fresh()->estado);
        $this->assertSeats($source);
        $this->assertSeats($target);
        // The same editor may reactivate: no separate reactivation permission exists.
        $this->actingAs(User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->firstOrFail())
            ->patchJson(route('admin.matriculas.anular', $enrollment))->assertRedirect();
        $this->actingAs($editor)->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pendiente'))->assertRedirect();
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public function test_creation_permission_does_not_grant_edit_annul_delete_or_restore(): void
    {
        [, $course, $enrollment] = $this->enrollment();
        $this->adminUser();
        $secretary = User::factory()->create();
        $secretary->assignRole('secretaria');
        $this->actingAs($secretary)->putJson(route('admin.matriculas.update', $enrollment), [])->assertForbidden();
        $this->patchJson(route('admin.matriculas.anular', $enrollment))->assertForbidden();
        $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertForbidden();
        $enrollment->delete();
        $course->increment('cupos_disponibles');
        $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertForbidden();
        $this->assertSoftDeleted($enrollment);
        $this->assertSeats($course);
    }

    public static function blockedAcquisitions(): iterable
    {
        foreach ([false, true] as $restore) {
            foreach ([false, true] as $duplicate) {
                yield 'restore='.(int) $restore.'/duplicate='.(int) $duplicate => [$restore, $duplicate];
            }
        }
    }

    #[DataProvider('blockedAcquisitions')]
    public function test_reactivation_and_restore_reject_duplicate_or_full_course(bool $restore, bool $duplicate): void
    {
        [$student, $course, $enrollment] = $this->enrollment($restore ? 'pagada' : 'anulada');
        $this->actingAs($this->adminUser());
        if ($restore) {
            $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        }
        $other = $duplicate ? $student : Estudiante::create(['dni' => '71000002', 'nombres' => 'Luis', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $course->refresh()->update(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $this->postJson(route('admin.matriculas.store'), $this->data($other, $course, 'pendiente'))->assertRedirect();
        $response = $restore
            ? $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))
            : $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $course, 'pendiente'));
        $response->assertUnprocessable()->assertJsonValidationErrors($duplicate ? 'estudiante_id' : 'curso_id');
        $this->assertSame($restore ? 'pagada' : 'anulada', Matricula::withTrashed()->findOrFail($enrollment->id)->estado);
        $this->assertSame($restore, Matricula::withTrashed()->findOrFail($enrollment->id)->trashed());
        $this->assertSeats($course);
    }

    public static function lateConflicts(): iterable
    {
        yield 'transfer last seat' => [true, false];
        yield 'transfer duplicate' => [true, true];
        yield 'reactivate last seat' => [false, false];
        yield 'reactivate duplicate' => [false, true];
    }

    #[DataProvider('lateConflicts')]
    public function test_acquisition_rechecks_conflicts_after_validation(bool $transfer, bool $duplicate): void
    {
        [$student, $source, $enrollment] = $this->enrollment($transfer ? 'pagada' : 'anulada');
        $target = $transfer ? $this->course('TARGET', 1) : $source;
        if (! $transfer) {
            $source->update(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        }
        $other = $duplicate ? $student : Estudiante::create(['dni' => '71000002', 'nombres' => 'Luis', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $this->actingAs($this->adminUser());
        $this->app->afterResolving(UpdateMatriculaRequest::class, function () use ($other, $target): void {
            Matricula::create($this->data($other, $target, 'pendiente'));
            $target->decrement('cupos_disponibles');
        });
        $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pagada'))
            ->assertUnprocessable()->assertJsonValidationErrors($duplicate ? 'estudiante_id' : 'curso_id');
        $this->assertSame($transfer ? 'pagada' : 'anulada', $enrollment->fresh()->estado);
        $this->assertSame($source->id, $enrollment->fresh()->curso_id);
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public function test_annul_releases_seat_once_even_with_deleted_parents(): void
    {
        [$student, $course, $enrollment] = $this->enrollment('pagada');
        $student->delete();
        $course->delete();
        $this->actingAs($this->adminUser());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->patchJson(route('admin.matriculas.anular', $enrollment))->assertRedirect();
            $this->assertSame('anulada', $enrollment->fresh()->estado);
            $this->assertSeats($course);
        }
    }

    public function test_coordinator_can_restore_but_cannot_delete(): void
    {
        [, $course, $enrollment] = $this->enrollment('pagada');
        $this->actingAs($this->adminUser())->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        $coordinator = User::factory()->create();
        $coordinator->assignRole('coordinador');
        $this->actingAs($coordinator)->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertRedirect();
        $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertForbidden();
        $this->assertSame('pagada', $enrollment->fresh()->estado);
        $this->assertSeats($course);
    }

    private function enrollment(string $state = 'pendiente'): array
    {
        $student = Estudiante::create(['dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $course = $this->course('SOURCE');
        $enrollment = Matricula::create($this->data($student, $course, $state));
        if ($state !== 'anulada') {
            $course->decrement('cupos_disponibles');
        }

        return [$student, $course, $enrollment];
    }

    private function course(string $code, int $capacity = 2): Curso
    {
        return Curso::create([
            'codigo' => $code, 'nombre' => 'Curso ficticio', 'descripcion' => 'Prueba', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Prueba',
            'cupos_maximos' => $capacity, 'cupos_disponibles' => $capacity, 'estado' => 'activo',
        ]);
    }

    private function data(Estudiante $student, Curso $course, string $state): array
    {
        return ['estudiante_id' => $student->id, 'curso_id' => $course->id, 'fecha_matricula' => '2026-10-28', 'estado' => $state];
    }

    private function assertSeats(Curso $course): void
    {
        $course->refresh();
        $occupied = Matricula::where('curso_id', $course->id)->where('estado', '!=', 'anulada')->count();
        $this->assertSame($course->cupos_maximos - $occupied, $course->cupos_disponibles);
    }
}
