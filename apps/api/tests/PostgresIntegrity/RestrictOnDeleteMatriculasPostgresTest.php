<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Migración 000040 (RESTRICT en matriculas). PREPARADA, NO EJECUTADA: requiere el perfil PostgreSQL aislado con
 * consentimiento destructivo explícito (puerto 55433). Se omite mientras la migración no esté aplicada allí.
 */
class RestrictOnDeleteMatriculasPostgresTest extends TestCase
{
    use RefreshDatabase;
    use RequiresIsolatedPostgres {
        setUp as requireIsolatedPostgres;
    }

    private const FOREIGN_KEY_VIOLATION = '23503';

    protected function setUp(): void
    {
        $this->requireIsolatedPostgres();

        $tipo = DB::selectOne("select confdeltype::text as t from pg_constraint where conname = 'matriculas_curso_id_foreign'");
        if ($tipo === null || $tipo->t !== 'r') {
            $this->markTestSkipped('La migración 000040 aún no se ha aprobado ni aplicado en esta base.');
        }
    }

    public function test_un_delete_fisico_con_matriculas_es_rechazado_y_no_borra_nada(): void
    {
        $estudianteId = DB::table('estudiantes')->insertGetId([
            'dni' => '33000001', 'nombres' => 'Prueba', 'apellidos' => 'Restrict', 'estado' => 'activo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cursoId = DB::table('cursos')->insertGetId([
            'codigo' => 'PG-RS1', 'nombre' => 'Curso restrict', 'descripcion' => 'Prueba', 'categoria' => 'otros',
            'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Interno',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('matriculas')->insert([
            'estudiante_id' => $estudianteId, 'curso_id' => $cursoId, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['estudiantes' => $estudianteId, 'cursos' => $cursoId] as $tabla => $id) {
            try {
                DB::transaction(fn () => DB::table($tabla)->where('id', $id)->delete());
                $this->fail("El DELETE físico en {$tabla} debía ser rechazado.");
            } catch (QueryException $e) {
                $this->assertSame(self::FOREIGN_KEY_VIOLATION, $e->getCode());
            }
        }

        $this->assertSame(1, DB::table('matriculas')->where('estudiante_id', $estudianteId)->count());
    }

    public function test_matricula_notifications_conserva_cascade(): void
    {
        $tipo = DB::selectOne("select confdeltype::text as t from pg_constraint where conname = 'matricula_notifications_matricula_id_foreign'");

        $this->assertSame('c', $tipo->t);
    }
}
