<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Restricciones de base de datos PROPUESTAS en docs/postgresql-l2.2-c-integridad.md.
 * PREPARADAS, NO EJECUTADAS: se omiten mientras la migración propuesta no exista.
 *
 * Escriben filas directamente (sin el panel) para comprobar que la base rechaza lo que la app
 * ya impide: así la integridad no depende solo de que todo pase por el controlador.
 */
class MatriculaConstraintsPostgresTest extends TestCase
{
    use RefreshDatabase;
    use RequiresIsolatedPostgres {
        setUp as requireIsolatedPostgres;
    }

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    protected function setUp(): void
    {
        $this->requireIsolatedPostgres();

        $existe = DB::selectOne(
            "select 1 as ok from pg_indexes where schemaname = current_schema() and indexname = 'matriculas_vigente_unica'"
        );
        if ($existe === null) {
            $this->markTestSkipped('La migración de restricciones propuesta aún no se ha aprobado ni aplicado.');
        }
    }

    public function test_database_rejects_two_active_enrollments_for_the_same_pair(): void
    {
        [$estudianteId, $cursoId] = $this->pair('31000001', 'PG-UQ1');
        $this->insertEnrollment($estudianteId, $cursoId, 'pendiente');

        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => $this->insertEnrollment($estudianteId, $cursoId, 'pagada'));
    }

    public function test_database_allows_new_enrollment_when_previous_ones_are_annulled_or_deleted(): void
    {
        [$estudianteId, $cursoId] = $this->pair('31000002', 'PG-UQ2');
        $this->insertEnrollment($estudianteId, $cursoId, 'anulada');
        $this->insertEnrollment($estudianteId, $cursoId, 'pendiente', deleted: true);
        $this->insertEnrollment($estudianteId, $cursoId, 'pendiente');

        $this->assertSame(3, DB::table('matriculas')->where('estudiante_id', $estudianteId)->count());
    }

    public function test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one(): void
    {
        [$estudianteId, $cursoId] = $this->pair('31000003', 'PG-UQ3');
        $eliminada = $this->insertEnrollment($estudianteId, $cursoId, 'pendiente', deleted: true);
        $this->insertEnrollment($estudianteId, $cursoId, 'pendiente');

        $this->assertViolation(
            self::UNIQUE_VIOLATION,
            fn () => DB::table('matriculas')->where('id', $eliminada)->update(['deleted_at' => null]),
        );
    }

    public function test_database_rejects_negative_or_excess_seats(): void
    {
        [, $cursoId] = $this->pair('31000004', 'PG-CK1');

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('cursos')->where('id', $cursoId)->update(['cupos_disponibles' => -1]));
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('cursos')->where('id', $cursoId)->update(['cupos_disponibles' => 6]));
    }

    public function test_database_rejects_negative_amounts_and_prices(): void
    {
        [$estudianteId, $cursoId] = $this->pair('31000005', 'PG-CK2');
        $matricula = $this->insertEnrollment($estudianteId, $cursoId, 'pagada');

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->update(['monto_pagado' => -0.01]));
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('cursos')->where('id', $cursoId)->update(['precio' => -1]));
    }

    /**
     * @return array{int, int}
     */
    private function pair(string $dni, string $codigo): array
    {
        return [$this->makeStudent($dni)->id, $this->makeCourse($codigo, 5)->id];
    }

    private function insertEnrollment(int $estudianteId, int $cursoId, string $estado, bool $deleted = false): int
    {
        return (int) DB::table('matriculas')->insertGetId([
            'estudiante_id' => $estudianteId,
            'curso_id' => $cursoId,
            'fecha_matricula' => now()->toDateString(),
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function assertViolation(string $sqlState, callable $write): void
    {
        // Un savepoint aísla el error: sin él PostgreSQL aborta la transacción de RefreshDatabase.
        DB::statement('savepoint constraint_probe');

        try {
            $write();
            $this->fail("Se esperaba la violación SQLSTATE {$sqlState}.");
        } catch (QueryException $error) {
            $this->assertSame($sqlState, $error->getCode());
        } finally {
            DB::statement('rollback to savepoint constraint_probe');
        }
    }
}
