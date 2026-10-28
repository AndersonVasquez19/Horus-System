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
        yield 'archived course' => ['activo', 'archivado', null];
        yield 'mixed inactive archived' => ['inactivo', 'archivado', null];
        yield 'deleted student' => ['activo', 'activo', 'student'];
        yield 'deleted course' => ['activo', 'activo', 'course'];
    }

    #[DataProvider('parentConditions')]
    public function test_transfer_and_reactivation_require_eligible_parents(string $studentState, string $courseState, ?string $deleted): void
    {
        [$student, $source, $enrollment] = $this->enrollment('anulada');
        $target = $this->course('TARGET');
        $student->update(['estado' => $studentState]);
        $target->update(['estado' => $courseState]);
        if ($deleted !== null) {
            ($deleted === 'student' ? $student : $target)->delete();
        }
        $response = $this->actingAs($this->adminUser())->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pagada'));
        if ($deleted !== null || $studentState !== 'activo' || $courseState !== 'activo') {
            $response->assertUnprocessable()->assertJsonValidationErrors($deleted === 'student' || $studentState !== 'activo' ? 'estudiante_id' : 'curso_id');
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
    public function test_restore_requires_eligible_parents_only_for_current_occupation(string $state, string $studentState, string $courseState, ?string $deleted): void
    {
        [$student, $course, $enrollment] = $this->enrollment($state);
        $this->actingAs($this->adminUser())->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        $this->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertNotFound();
        $student->update(['estado' => $studentState]);
        $course->update(['estado' => $courseState]);
        if ($deleted !== null) {
            ($deleted === 'student' ? $student : $course)->delete();
        }
        if ($state !== 'anulada' && ($deleted !== null || $studentState !== 'activo' || $courseState !== 'activo')) {
            $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertUnprocessable();
            $this->assertSoftDeleted($enrollment);
            $this->assertSame($state, $enrollment->fresh()->estado);
            $this->assertSame(2, $course->fresh()->cupos_disponibles);
            $this->assertSeats($course);

            return;
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
        yield 'transfer occupied seat' => ['transfer', 'updating'];
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
                'update', 'transfer' => $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pendiente')),
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

    public static function acquisitionEligibility(): iterable
    {
        foreach (self::parentConditions() as $name => $condition) {
            foreach (['transfer', 'reactivate', 'create'] as $operation) {
                yield "$operation / $name" => [$operation, ...$condition];
            }
        }
    }

    #[DataProvider('acquisitionEligibility')]
    public function test_each_acquisition_checks_eligibility_without_partial_writes(string $operation, string $studentState, string $courseState, ?string $deleted): void
    {
        [$student, $source, $enrollment] = $this->enrollment('anulada');
        $target = $operation === 'reactivate' ? $source : $this->course('TARGET');
        if ($operation === 'transfer') {
            $enrollment->update(['estado' => 'pagada']);
            $source->decrement('cupos_disponibles');
        }
        $student->update(['estado' => $studentState]);
        $target->update(['estado' => $courseState]);
        if ($deleted !== null) {
            ($deleted === 'student' ? $student : $target)->delete();
        }
        $before = DB::table('matriculas')->where('id', $enrollment->id)->first();
        $sourceSeats = $source->fresh()->cupos_disponibles;
        $targetSeats = $target->fresh()->cupos_disponibles;
        $this->actingAs($this->adminUser());
        $response = $operation === 'create'
            ? $this->postJson(route('admin.matriculas.store'), $this->data($student, $target, 'pendiente'))
            : $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pendiente'));
        if ($studentState === 'activo' && $courseState === 'activo' && $deleted === null) {
            $response->assertRedirect();
        } else {
            $response->assertUnprocessable();
            $this->assertEquals($before, DB::table('matriculas')->where('id', $enrollment->id)->first());
            $this->assertSame($sourceSeats, $source->fresh()->cupos_disponibles);
            $this->assertSame($targetSeats, $target->fresh()->cupos_disponibles);
        }
        $this->assertSeats($source);
        $this->assertSeats($target);
        $this->assertSame($deleted === 'student', $student->fresh()->trashed());
        $this->assertSame($deleted === 'course', $target->fresh()->trashed());
        Http::assertNothingSent();
    }

    public static function historicalEdits(): iterable
    {
        foreach (['pendiente', 'pagada', 'anulada'] as $state) {
            foreach (['inactivo', 'archivado'] as $courseState) {
                yield "$state / $courseState" => [$state, $courseState];
            }
        }
    }

    #[DataProvider('historicalEdits')]
    public function test_historical_corrections_with_inactive_parents_keep_occupation(string $state, string $courseState): void
    {
        [$student, $course, $enrollment] = $this->enrollment($state);
        $student->update(['estado' => 'inactivo']);
        $course->update(['estado' => $courseState, 'cupos_maximos' => $state === 'anulada' ? 0 : 1, 'cupos_disponibles' => 0]);
        $data = $this->data($student, $course, $state === 'pendiente' ? 'pagada' : $state);
        $data['observaciones'] = 'Corrección histórica';
        $this->actingAs($this->adminUser())->putJson(route('admin.matriculas.update', $enrollment), $data)->assertRedirect();
        $this->assertSame('Corrección histórica', $enrollment->fresh()->observaciones);
        $this->assertSame(0, $course->fresh()->cupos_disponibles);
        $this->assertSeats($course);
    }

    public static function lateEligibilityChanges(): iterable
    {
        foreach (['transfer', 'reactivate'] as $operation) {
            foreach (['student inactive', 'student deleted', 'course inactive', 'course archived', 'course deleted'] as $change) {
                yield "$operation / $change" => [$operation, $change];
            }
        }
    }

    #[DataProvider('lateEligibilityChanges')]
    public function test_eligibility_is_rechecked_after_request_validation(string $operation, string $change): void
    {
        [$student, $source, $enrollment] = $this->enrollment($operation === 'transfer' ? 'pagada' : 'anulada');
        $target = $operation === 'transfer' ? $this->course('TARGET') : $source;
        $this->actingAs($this->adminUser());
        $before = DB::table('matriculas')->where('id', $enrollment->id)->first();
        $this->app->afterResolving(UpdateMatriculaRequest::class, function () use ($student, $target, $change): void {
            match ($change) {
                'student inactive' => $student->update(['estado' => 'inactivo']),
                'student deleted' => $student->delete(),
                'course inactive' => $target->update(['estado' => 'inactivo']),
                'course archived' => $target->update(['estado' => 'archivado']),
                'course deleted' => $target->delete(),
            };
        });
        $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pendiente'))
            ->assertUnprocessable()->assertJsonValidationErrors(str_starts_with($change, 'student') ? 'estudiante_id' : 'curso_id');
        $this->assertEquals($before, DB::table('matriculas')->where('id', $enrollment->id)->first());
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public function test_restoring_annulled_history_with_deleted_parents_and_duplicate_needs_no_seat(): void
    {
        [$student, $course, $enrollment] = $this->enrollment('anulada');
        $course->update(['cupos_maximos' => 1, 'cupos_disponibles' => 1]);
        $this->actingAs($this->adminUser())->deleteJson(route('admin.matriculas.destroy', $enrollment))->assertRedirect();
        $this->postJson(route('admin.matriculas.store'), $this->data($student, $course, 'pagada'))->assertRedirect();
        $student->delete();
        $course->delete();
        $notifications = DB::table('matricula_notifications')->count();
        $this->patchJson(route('admin.matriculas.restaurar', $enrollment->id))->assertRedirect();
        $this->assertNotSoftDeleted($enrollment);
        $this->assertSame('anulada', $enrollment->fresh()->estado);
        $this->assertSoftDeleted($student);
        $this->assertSoftDeleted($course);
        $this->assertSame(0, $course->fresh()->cupos_disponibles);
        $this->assertSeats($course);
        $this->assertDatabaseCount('matricula_notifications', $notifications);
        Http::assertNothingSent();
    }

    public function test_annulled_history_can_still_be_created_in_a_full_course_with_a_current_enrollment(): void
    {
        [$student, $course] = $this->enrollment('pagada');
        $course->update(['cupos_maximos' => 1, 'cupos_disponibles' => 0]);
        $this->actingAs($this->adminUser())->postJson(route('admin.matriculas.store'), $this->data($student, $course, 'anulada'))->assertRedirect();
        $this->assertSame(1, Matricula::where('estado', 'anulada')->count());
        $this->assertSame(0, $course->fresh()->cupos_disponibles);
        $this->assertSeats($course);
    }

    public function test_changed_relations_during_lock_discovery_reject_without_partial_writes(): void
    {
        [$student, $source, $enrollment] = $this->enrollment('pagada');
        $target = $this->course('TARGET');
        $this->actingAs($this->adminUser());
        $before = DB::table('matriculas')->where('id', $enrollment->id)->first();
        $changed = false;
        // One connection, deterministic interleaving only; not PostgreSQL concurrency.
        $this->app->afterResolving(UpdateMatriculaRequest::class, function () use ($source, $target, $enrollment, &$changed): void {
            DB::listen(function ($query) use ($source, $target, $enrollment, &$changed): void {
                if (! $changed && DB::transactionLevel() > 0 && str_contains($query->sql, 'select * from "matriculas"')) {
                    $changed = true;
                    $source->increment('cupos_disponibles');
                    $target->decrement('cupos_disponibles');
                    Matricula::whereKey($enrollment->id)->update(['curso_id' => $target->id]);
                }
            });
        });
        $this->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $source, 'pagada'))
            ->assertUnprocessable()->assertJsonValidationErrors('curso_id');
        $this->assertTrue($changed);
        $this->assertEquals($before, DB::table('matriculas')->where('id', $enrollment->id)->first());
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public static function historicalTransfers(): iterable
    {
        yield 'annulled history' => ['anulada'];
        yield 'transfer and annul' => ['pagada'];
    }

    #[DataProvider('historicalTransfers')]
    public function test_transfer_without_destination_occupation_keeps_historical_contract(string $state): void
    {
        [$student, $source, $enrollment] = $this->enrollment($state);
        $target = $this->course('TARGET', 0);
        $student->update(['estado' => 'inactivo']);
        $target->update(['estado' => 'archivado']);
        $this->actingAs($this->adminUser())->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'anulada'))->assertRedirect();
        $this->assertSame('anulada', $enrollment->fresh()->estado);
        $this->assertSame($target->id, $enrollment->fresh()->curso_id);
        $this->assertSeats($source);
        $this->assertSeats($target);
    }

    public static function historicalSources(): iterable
    {
        yield 'inactive' => ['inactivo'];
        yield 'archived' => ['archivado'];
        yield 'deleted' => ['deleted'];
    }

    #[DataProvider('historicalSources')]
    public function test_transfer_can_release_occupation_from_an_unavailable_source(string $condition): void
    {
        [$student, $source, $enrollment] = $this->enrollment('pagada');
        $target = $this->course('TARGET');
        if ($condition === 'deleted') {
            $source->delete();
        } else {
            $source->update(['estado' => $condition]);
        }
        $this->actingAs($this->adminUser())->putJson(route('admin.matriculas.update', $enrollment), $this->data($student, $target, 'pagada'))->assertRedirect();
        $this->assertSame($target->id, $enrollment->fresh()->curso_id);
        $this->assertSame($condition === 'deleted', $source->fresh()->trashed());
        $this->assertSeats($source);
        $this->assertSeats($target);
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
