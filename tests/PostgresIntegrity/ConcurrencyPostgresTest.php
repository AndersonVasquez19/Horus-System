<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Carreras entre dos sesiones PostgreSQL. PREPARADAS, NO EJECUTADAS. Datos ficticios.
 *
 * Usan DatabaseTruncation (no RefreshDatabase): con RefreshDatabase todo ocurre en una transacción
 * sin confirmar y la segunda sesión no vería los datos. Solo es aceptable en la base desechable.
 *
 * Protocolo determinista, sin hilos ni procesos:
 *  1. La sesión B (otra "petición") abre una transacción y toma el mismo bloqueo que el código.
 *  2. La sesión principal ejecuta la petición real con lock_timeout corto: debe QUEDAR BLOQUEADA
 *     (error 55P03), lo que demuestra que no puede leer un estado obsoleto.
 *  3. B confirma; la petición principal se repite y debe decidir con el estado ya confirmado.
 */
class ConcurrencyPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const LOCK_NOT_AVAILABLE = '55P03';

    public function test_two_enrollments_competing_for_the_last_seat_cannot_both_succeed(): void
    {
        $curso = $this->makeCourse('PG-RACE', 1);
        $primero = $this->makeStudent('32000001');
        $segundo = $this->makeStudent('32000002');
        $admin = $this->adminUser();
        $b = $this->secondSession();

        // B: otra petición que ya tomó el bloqueo del curso y está ocupando el último cupo.
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);
        $b->table('matriculas')->insert([
            'estudiante_id' => $primero->id, 'curso_id' => $curso->id, 'estado' => 'pendiente',
            'fecha_matricula' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b->update('update cursos set cupos_disponibles = cupos_disponibles - 1 where id = ?', [$curso->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($segundo, $curso)));

        $b->commit();

        $this->withExceptionHandling()
            ->actingAs($admin)
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($segundo, $curso))
            ->assertSessionHasErrors('curso_id');

        $this->assertSame(0, $curso->fresh()->cupos_disponibles);
        $this->assertSame(1, Matricula::query()->where('curso_id', $curso->id)->count());
    }

    public function test_two_requests_enrolling_the_same_student_cannot_create_a_duplicate(): void
    {
        $curso = $this->makeCourse('PG-RDUP', 5);
        $estudiante = $this->makeStudent('32000003');
        $admin = $this->adminUser();
        $b = $this->secondSession();

        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);
        $b->table('matriculas')->insert([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'estado' => 'pendiente',
            'fecha_matricula' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b->update('update cursos set cupos_disponibles = cupos_disponibles - 1 where id = ?', [$curso->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($estudiante, $curso)));

        $b->commit();

        $this->withExceptionHandling()
            ->actingAs($admin)
            ->post(route('admin.matriculas.store'), $this->enrollmentPayload($estudiante, $curso))
            ->assertSessionHasErrors('estudiante_id');

        $this->assertSame(1, $this->activeEnrollments($estudiante, $curso));
        $this->assertSame(4, $curso->fresh()->cupos_disponibles);
    }

    public function test_two_concurrent_demotions_cannot_remove_every_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $uno = $this->staffWithRole('admin');
        $dos = $this->staffWithRole('admin');
        $gestor = $this->staffWithRole('coordinador');
        $gestor->givePermissionTo(['usuarios.editar', 'usuarios.asignar-roles']);
        $b = $this->secondSession();

        // B: otra petición dentro de AdminProtection::runExclusively() degradando a $uno.
        $adminRoleId = (int) DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        $b->beginTransaction();
        $b->select('select id from roles where id = ? for update', [$adminRoleId]);
        $b->delete(
            'delete from model_has_roles where role_id = ? and model_id = ? and model_type = ?',
            [$adminRoleId, $uno->id, User::class],
        );

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($gestor)->withoutExceptionHandling()
            ->put(route('admin.usuarios.update', $dos), ['name' => $dos->name, 'email' => $dos->email, 'roles' => ['secretaria']]));

        $b->commit();

        // Con $uno ya degradado, $dos es el último administrador y no puede perder el rol.
        $this->withExceptionHandling()
            ->actingAs($gestor)
            ->put(route('admin.usuarios.update', $dos), ['name' => $dos->name, 'email' => $dos->email, 'roles' => ['secretaria']])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($dos->fresh()->hasRole('admin'));
        $this->assertSame(1, User::role('admin')->count());
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
