<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * CHECK de estados y modalidades (migración 000039). PREPARADA, NO EJECUTADA: requiere el perfil PostgreSQL
 * aislado con consentimiento destructivo explícito (puerto 55433). Se omite mientras la migración no esté
 * aplicada en esa base.
 */
class EstadosCheckPostgresTest extends TestCase
{
    use RefreshDatabase;
    use RequiresIsolatedPostgres {
        setUp as requireIsolatedPostgres;
    }

    private const CHECK_VIOLATION = '23514';

    protected function setUp(): void
    {
        $this->requireIsolatedPostgres();

        $existe = DB::selectOne("select 1 as ok from pg_constraint where conname = 'matriculas_estado_valido'");
        if ($existe === null) {
            $this->markTestSkipped('La migración 000039 aún no se ha aprobado ni aplicado en esta base.');
        }
    }

    public function test_la_base_rechaza_estados_invalidos_en_matriculas_cursos_y_estudiantes(): void
    {
        $this->assertViolation(fn () => DB::table('estudiantes')->insert($this->estudiante('32000001', 'borrado')));
        $this->assertViolation(fn () => DB::table('cursos')->insert($this->curso('PG-CK1', 'activo', 'remota')));
        $this->assertViolation(fn () => DB::table('cursos')->insert($this->curso('PG-CK2', 'cerrado', 'virtual')));

        $estudianteId = DB::table('estudiantes')->insertGetId($this->estudiante('32000002', 'activo'));
        $cursoId = DB::table('cursos')->insertGetId($this->curso('PG-CK3', 'activo', 'hibrido'));
        $this->assertViolation(fn () => DB::table('matriculas')->insert([
            'estudiante_id' => $estudianteId, 'curso_id' => $cursoId, 'fecha_matricula' => '2026-11-10',
            'estado' => 'archivada', 'created_at' => now(), 'updated_at' => now(),
        ]));

        DB::table('matriculas')->insert([
            'estudiante_id' => $estudianteId, 'curso_id' => $cursoId, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertViolation(fn () => DB::table('matriculas')->where('estudiante_id', $estudianteId)->update(['estado' => 'otro']));
    }

    public function test_los_estados_validos_siguen_pasando(): void
    {
        foreach (['activo', 'inactivo', 'archivado'] as $i => $estado) {
            DB::table('cursos')->insert($this->curso('PG-OK'.$i, $estado, 'presencial'));
        }

        $this->assertSame(3, DB::table('cursos')->whereIn('codigo', ['PG-OK0', 'PG-OK1', 'PG-OK2'])->count());
    }

    public function test_el_preflight_detiene_la_migracion_si_hay_datos_incompatibles(): void
    {
        $migracion = require database_path('migrations/2026_11_10_000039_add_check_estados_academicos.php');
        $migracion->down();
        DB::table('estudiantes')->insert($this->estudiante('32000009', 'raro'));

        try {
            $migracion->up();
            $this->fail('La migración debía detenerse por datos incompatibles.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('estudiantes.estado', $e->getMessage());
            $this->assertStringNotContainsString('32000009', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function estudiante(string $dni, string $estado): array
    {
        return ['dni' => $dni, 'nombres' => 'Prueba', 'apellidos' => 'Check', 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return array<string, mixed> */
    private function curso(string $codigo, string $estado, string $modalidad): array
    {
        return [
            'codigo' => $codigo, 'nombre' => 'Curso '.$codigo, 'descripcion' => 'Prueba', 'categoria' => 'otros',
            'duracion_horas' => 10, 'precio' => 100, 'modalidad' => $modalidad, 'horario' => 'Interno',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => $estado, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertViolation(callable $accion): void
    {
        try {
            DB::transaction($accion);
            $this->fail('La base debía rechazar el valor.');
        } catch (QueryException $e) {
            $this->assertSame(self::CHECK_VIOLATION, $e->getCode());
        }
    }
}
