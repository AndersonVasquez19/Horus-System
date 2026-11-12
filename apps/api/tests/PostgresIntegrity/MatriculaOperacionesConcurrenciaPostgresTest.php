<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Support\AuditoriaAcademica;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Concurrencia de las operaciones de matrícula y de su auditoría (Sprint 21.2). PREPARADAS, NO EJECUTADAS.
 * Datos ficticios. Solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test).
 *
 * Mismo protocolo determinista que ConcurrencyPostgresTest, sin hilos ni procesos:
 *  1. La sesión B (otra «petición») abre una transacción y toma el bloqueo del curso, como hace el código.
 *  2. La petición real, con lock_timeout corto, debe QUEDAR BLOQUEADA (55P03): no puede leer un estado obsoleto.
 *     La transacción se revierte entera, por lo que no deja matrícula, cupo ni auditoría.
 *  3. B confirma y la petición se repite: decide con el estado ya confirmado.
 *
 * Limitación: no reproduce un deadlock real (40P01) por orden de bloqueo; no es determinista con una sola sesión PHP.
 * Usan DatabaseTruncation: con RefreshDatabase la segunda sesión no vería los datos.
 */
class MatriculaOperacionesConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const LOCK_NOT_AVAILABLE = '55P03';

    /** 1. Alta: el intento bloqueado no deja rastro y, tras confirmar B, se rechaza por falta de cupo. */
    public function test_store_blocked_attempt_leaves_no_enrollment_seat_or_audit_and_is_rejected_afterwards(): void
    {
        $curso = $this->makeCourse('PGA-STORE', 1);
        $primero = $this->makeStudent('33000001');
        $segundo = $this->makeStudent('33000002');
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionFillsLastSeat($b, $curso, $primero);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($segundo, $curso)));

        $this->assertSame(0, AuditLog::where('action', 'matricula_creada')->count());
        $this->assertSame(0, MatriculaNotification::count());

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($segundo, $curso))
            ->assertSessionHasErrors('curso_id');

        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
        $this->assertSame(1, Matricula::query()->where('curso_id', $curso->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'matricula_creada')->count());
    }

    /** 2. Anular contra anular: el cupo vuelve una sola vez y no hay un segundo evento. */
    public function test_two_annulments_return_the_seat_once_and_audit_nothing_twice(): void
    {
        $curso = $this->makeCourse('PGA-ANULAR', 3);
        $estudiante = $this->makeStudent('33000003');
        $matricula = $this->seedEnrollment($estudiante, $curso);
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionAnnuls($b, $curso, $matricula);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->patch(route('admin.matriculas.anular', $matricula)));

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->patch(route('admin.matriculas.anular', $matricula))
            ->assertRedirect();

        $this->assertSame(3, $curso->fresh()->cupos_disponibles, 'El cupo no puede devolverse dos veces.');
        $this->assertSame('anulada', $matricula->fresh()->estado);
        $this->assertSame(0, AuditLog::where('action', 'matricula_anulada')->count());
    }

    /** 3. PUT que anula contra una anulación confirmada: sin segunda liberación ni evento «anulada». */
    public function test_put_that_annuls_after_a_committed_annulment_does_not_release_again(): void
    {
        $curso = $this->makeCourse('PGA-PUT', 3);
        $estudiante = $this->makeStudent('33000004');
        $matricula = $this->seedEnrollment($estudiante, $curso);
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionAnnuls($b, $curso, $matricula);

        $payload = $this->enrollmentPayload($estudiante, $curso, 'anulada');
        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->put(route('admin.matriculas.update', $matricula), $payload));

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->put(route('admin.matriculas.update', $matricula), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $curso->fresh()->cupos_disponibles, 'El estado releído bajo bloqueo ya era anulada.');
        $this->assertSame('anulada', $matricula->fresh()->estado);
        $this->assertSame(0, AuditLog::where('action', 'matricula_anulada')->count());
    }

    /** 4. Eliminar contra una anulación confirmada: no libera cupo otra vez y el efecto auditado es «ninguno». */
    public function test_destroy_after_a_committed_annulment_releases_no_second_seat(): void
    {
        $curso = $this->makeCourse('PGA-DESTROY', 3);
        $estudiante = $this->makeStudent('33000005');
        $matricula = $this->seedEnrollment($estudiante, $curso);
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionAnnuls($b, $curso, $matricula);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->delete(route('admin.matriculas.destroy', $matricula)));
        $this->assertSame(0, AuditLog::where('action', 'matricula_eliminada')->count());

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->delete(route('admin.matriculas.destroy', $matricula))
            ->assertRedirect();

        $this->assertSame(3, $curso->fresh()->cupos_disponibles);
        $this->assertTrue(Matricula::withTrashed()->findOrFail($matricula->id)->trashed());
        $evento = AuditLog::where('action', 'matricula_eliminada')->sole();
        $this->assertSame(AuditoriaAcademica::EFECTO_NINGUNO, $evento->changes['efecto_cupo']);
    }

    /** 5. Restaurar contra un alta que toma el último cupo: se rechaza y no audita. */
    public function test_restore_is_rejected_when_another_enrollment_took_the_last_seat(): void
    {
        $curso = $this->makeCourse('PGA-RESTORE', 1);
        $dueno = $this->makeStudent('33000006');
        $otro = $this->makeStudent('33000007');
        $eliminada = $this->seedEnrollment($dueno, $curso, 'pendiente', deleted: true);
        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionFillsLastSeat($b, $curso, $otro);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->patch(route('admin.matriculas.restaurar', $eliminada->id)));

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->patch(route('admin.matriculas.restaurar', $eliminada->id))
            ->assertSessionHasErrors('curso_id');

        $this->assertTrue(Matricula::withTrashed()->findOrFail($eliminada->id)->trashed());
        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
        $this->assertSame(0, AuditLog::where('action', 'matricula_restaurada')->count());
    }

    /** 6. Traslado hacia un curso cuyo último cupo toma B: se rechaza y el curso de origen queda intacto. */
    public function test_transfer_to_a_course_filled_by_another_session_leaves_the_origin_untouched(): void
    {
        $origen = $this->makeCourse('PGA-ORIGEN', 2);
        $destino = $this->makeCourse('PGA-DESTINO', 1);
        $estudiante = $this->makeStudent('33000008');
        $otro = $this->makeStudent('33000009');
        $matricula = $this->seedEnrollment($estudiante, $origen);
        $this->assertSame(1, $origen->fresh()->cupos_disponibles);
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $this->sessionFillsLastSeat($b, $destino, $otro);

        $payload = $this->enrollmentPayload($estudiante, $destino);
        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->put(route('admin.matriculas.update', $matricula), $payload));

        $b->commit();

        $this->withExceptionHandling()->actingAs($admin)
            ->put(route('admin.matriculas.update', $matricula), $payload)
            ->assertSessionHasErrors('curso_id');

        $this->assertSame($origen->id, $matricula->fresh()->curso_id);
        $this->assertSame(1, $origen->fresh()->cupos_disponibles);
        $this->assertSame(0, $destino->fresh()->cupos_disponibles);
        $this->assertSame(0, AuditLog::where('action', 'matricula_actualizada')->count());
    }

    /** 7. Atomicidad en PostgreSQL: si falla la auditoría se revierten matrícula, cupo y notificación. */
    public function test_audit_failure_rolls_back_enrollment_seat_and_notification(): void
    {
        $curso = $this->makeCourse('PGA-ATOMIC', 2);
        $estudiante = $this->makeStudent('33000010');
        $admin = $this->adminUser();

        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });

        try {
            $this->actingAs($admin)->withoutExceptionHandling()
                ->post(route('admin.matriculas.store'), $this->enrollmentPayload($estudiante, $curso));
            $this->fail('El fallo de la auditoría debía propagarse.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de auditoría simulado', $error->getMessage());
        }

        $this->assertSame(0, Matricula::withTrashed()->count());
        $this->assertSame(2, $curso->fresh()->cupos_disponibles);
        $this->assertSame(0, MatriculaNotification::count());
        $this->assertSame(0, AuditLog::count());
    }

    /** B: otra petición que ya tomó el bloqueo del curso y ocupa su último cupo con otro estudiante. */
    private function sessionFillsLastSeat(Connection $b, Curso $curso, Estudiante $estudiante): void
    {
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);
        $b->table('matriculas')->insert([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'estado' => 'pendiente',
            'fecha_matricula' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b->update('update cursos set cupos_disponibles = cupos_disponibles - 1 where id = ?', [$curso->id]);
    }

    /** B: otra petición que ya tomó el bloqueo del curso y anuló la matrícula devolviendo su cupo. */
    private function sessionAnnuls(Connection $b, Curso $curso, Matricula $matricula): void
    {
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);
        $b->update('update matriculas set estado = ?, updated_at = ? where id = ?', ['anulada', now(), $matricula->id]);
        $b->update('update cursos set cupos_disponibles = cupos_disponibles + 1 where id = ?', [$curso->id]);
    }

    /** Matrícula sembrada por SQL directo (sin observer ni auditoría) que ocupa cupo si está vigente. */
    private function seedEnrollment(Estudiante $estudiante, Curso $curso, string $estado = 'pendiente', bool $deleted = false): Matricula
    {
        $id = DB::table('matriculas')->insertGetId([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'estado' => $estado,
            'fecha_matricula' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
        if (! $deleted && $estado !== 'anulada') {
            DB::table('cursos')->where('id', $curso->id)->decrement('cupos_disponibles');
        }

        return Matricula::withTrashed()->findOrFail($id);
    }

    private function assertBlocked(callable $request): void
    {
        try {
            $request();
            $this->fail('La petición debía esperar el bloqueo de la otra sesión.');
        } catch (QueryException $error) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $error->getCode());
        } finally {
            // La transacción de la petición ya se revirtió; se restablece la espera normal.
            DB::statement('SET lock_timeout = 0');
        }
    }
}
