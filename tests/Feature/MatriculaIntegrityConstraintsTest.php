<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Migración de integridad de matrículas en SQLite: el índice único parcial también aplica aquí.
 * Los CHECK son exclusivos de PostgreSQL (tests/PostgresIntegrity). Datos ficticios.
 */
class MatriculaIntegrityConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'matriculas_vigente_unica';

    public function test_database_rejects_a_second_active_enrollment_written_outside_the_panel(): void
    {
        [$estudiante, $curso] = $this->pair('40000001', 'INT-1');
        $this->insertEnrollment($estudiante, $curso, 'pendiente');

        $this->expectException(QueryException::class);
        $this->insertEnrollment($estudiante, $curso, 'pagada');
    }

    public function test_history_is_kept_and_re_enrollment_is_allowed_after_annulment_or_soft_delete(): void
    {
        [$estudiante, $curso] = $this->pair('40000002', 'INT-2');
        $this->insertEnrollment($estudiante, $curso, 'anulada');
        $this->insertEnrollment($estudiante, $curso, 'anulada');
        $this->insertEnrollment($estudiante, $curso, 'pagada', deleted: true);
        $this->insertEnrollment($estudiante, $curso, 'pendiente');

        $this->assertSame(4, Matricula::withTrashed()->where('estudiante_id', $estudiante->id)->count());
    }

    public function test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one(): void
    {
        [$estudiante, $curso] = $this->pair('40000003', 'INT-3');
        $eliminada = $this->insertEnrollment($estudiante, $curso, 'pendiente', deleted: true);
        $this->insertEnrollment($estudiante, $curso, 'pendiente');

        try {
            DB::table('matriculas')->where('id', $eliminada)->update(['deleted_at' => null]);
            $this->fail('El índice debía impedir la restauración.');
        } catch (QueryException) {
            $this->assertNotNull(DB::table('matriculas')->where('id', $eliminada)->value('deleted_at'));
        }
    }

    public function test_panel_restore_conflict_is_still_a_validation_error_not_a_server_error(): void
    {
        [$estudiante, $curso] = $this->pair('40000004', 'INT-4');
        $eliminada = Matricula::withTrashed()->findOrFail($this->insertEnrollment($estudiante, $curso, 'pendiente', deleted: true));
        $this->insertEnrollment($estudiante, $curso, 'pendiente');

        $this->actingAs($this->adminUser())
            ->patch(route('admin.matriculas.restaurar', $eliminada->id))
            ->assertSessionHasErrors('estudiante_id');

        $this->assertSoftDeleted($eliminada);
    }

    public function test_migration_aborts_before_any_change_when_existing_data_violates_the_rule(): void
    {
        $migracion = $this->migration();
        $migracion->down();
        $this->assertFalse($this->indexExists());

        [$estudiante, $curso] = $this->pair('40000005', 'INT-5');
        $this->insertEnrollment($estudiante, $curso, 'pendiente');
        $this->insertEnrollment($estudiante, $curso, 'pagada');

        try {
            $migracion->up();
            $this->fail('La migración debía detenerse ante duplicados vigentes.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString("estudiante {$estudiante->id} / curso {$curso->id}: 2 matrículas vigentes", $error->getMessage());
        }

        $this->assertFalse($this->indexExists(), 'No debe quedar ningún cambio parcial.');
        $this->assertSame(2, DB::table('matriculas')->count(), 'La comprobación previa no modifica datos.');
    }

    public function test_migration_reports_seat_and_amount_violations_before_any_change(): void
    {
        $migracion = $this->migration();
        $migracion->down();

        [$estudiante, $curso] = $this->pair('40000006', 'INT-6');
        DB::table('cursos')->where('id', $curso->id)->update(['cupos_disponibles' => -1]);
        $matricula = $this->insertEnrollment($estudiante, $curso, 'pagada');
        DB::table('matriculas')->where('id', $matricula)->update(['monto_pagado' => -5]);

        try {
            $migracion->up();
            $this->fail('La migración debía detenerse ante cupos o montos inválidos.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString("cursos incumple cursos_cupos_rango (ids: {$curso->id})", $error->getMessage());
            $this->assertStringContainsString("matriculas incumple matriculas_monto_no_negativo (ids: {$matricula})", $error->getMessage());
        }

        $this->assertFalse($this->indexExists());
    }

    public function test_migration_is_reversible(): void
    {
        $migracion = $this->migration();

        $migracion->down();
        $this->assertFalse($this->indexExists());

        $migracion->up();
        $this->assertTrue($this->indexExists());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_27_000013_add_matriculas_integrity_constraints.php');
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('matriculas'))->contains(fn (array $index) => $index['name'] === self::INDEX);
    }

    /**
     * @return array{Estudiante, Curso}
     */
    private function pair(string $dni, string $codigo): array
    {
        $estudiante = Estudiante::create(['dni' => $dni, 'nombres' => 'Estudiante', 'apellidos' => 'Ficticio', 'estado' => 'activo']);
        $curso = Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Curso ficticio.',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        ]);

        return [$estudiante, $curso];
    }

    private function insertEnrollment(Estudiante $estudiante, Curso $curso, string $estado, bool $deleted = false): int
    {
        return (int) DB::table('matriculas')->insertGetId([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
