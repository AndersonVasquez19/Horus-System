<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Fase 5.2 — planes de ejecución de los listados del panel con 100 000 matrículas SINTÉTICAS.
 *
 * Solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test) y la conexión protegida. Los datos se
 * generan con generate_series, se analizan, se consultan con EXPLAIN (ANALYZE, BUFFERS) y se eliminan al terminar
 * (TRUNCATE en finally). Imprime la evidencia; solo afirma recuentos y que los planes se pueden obtener: la elección
 * de índice depende de las estadísticas y se interpreta en el informe, no se fija aquí.
 */
class ExplainListadosPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const ESTUDIANTES = 5000;

    private const CURSOS = 20;

    public function test_planes_de_los_listados_con_cien_mil_matriculas_sinteticas(): void
    {
        try {
            $this->generarDatos();
            $this->assertSame(self::ESTUDIANTES * self::CURSOS, (int) DB::table('matriculas')->count());

            foreach (['matriculas', 'estudiantes', 'cursos'] as $tabla) {
                DB::statement("ANALYZE {$tabla}");
            }

            $consultas = [
                'Q1 listado por defecto (fecha desc, id desc, página 1)' => 'select * from matriculas where deleted_at is null order by fecha_matricula desc, id desc limit 20 offset 0',
                'Q2 total para la paginación' => 'select count(*) from matriculas where deleted_at is null',
                'Q3 filtro por estado' => "select * from matriculas where deleted_at is null and estado = 'pagada' order by fecha_matricula desc, id desc limit 20",
                'Q4 filtro por curso' => 'select * from matriculas where deleted_at is null and curso_id = 7 order by fecha_matricula desc, id desc limit 20',
                'Q5 página profunda (offset 50000)' => 'select * from matriculas where deleted_at is null order by fecha_matricula desc, id desc limit 20 offset 50000',
                'Q6 búsqueda por DNI/curso (exists)' => "select * from matriculas where deleted_at is null and (exists (select * from estudiantes where matriculas.estudiante_id = estudiantes.id and estudiantes.deleted_at is null and (lower(dni) like '%004217%' or lower(nombres) like '%004217%' or lower(apellidos) like '%004217%')) or exists (select * from cursos where matriculas.curso_id = cursos.id and (lower(codigo) like '%004217%' or lower(nombre) like '%004217%'))) order by fecha_matricula desc, id desc limit 20",
                'Q7 matrículas de un estudiante' => 'select * from matriculas where estudiante_id = 1234 and deleted_at is null order by id desc',
                'Q8 eliminadas (papelera)' => 'select * from matriculas where deleted_at is not null order by fecha_matricula desc, id desc limit 20',
            ];

            foreach ($consultas as $titulo => $sql) {
                $plan = collect(DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql))
                    ->map(fn ($fila) => (array) $fila)
                    ->map(fn (array $fila) => (string) reset($fila))
                    ->implode("\n");
                $this->assertNotSame('', $plan);
                fwrite(STDOUT, "\n[explain] {$titulo}\n{$plan}\n");
            }
        } finally {
            DB::statement('TRUNCATE matriculas, estudiantes, cursos RESTART IDENTITY CASCADE');
            $this->assertSame(0, (int) DB::table('matriculas')->count(), 'Los datos sintéticos se eliminaron.');
        }
    }

    private function generarDatos(): void
    {
        DB::statement(<<<'SQL'
            insert into cursos (codigo, nombre, descripcion, categoria, duracion_horas, precio, modalidad, horario,
                cupos_maximos, cupos_disponibles, estado, created_at, updated_at)
            select 'SINT-'||lpad(g::text, 4, '0'), 'Curso sintetico '||g, 'Sintetico', 'tecnologia', 10, 100, 'virtual', 'Lunes',
                1000000, 1000000, 'activo', now(), now()
            from generate_series(1, 20) g
            SQL);

        DB::statement(<<<'SQL'
            insert into estudiantes (dni, nombres, apellidos, estado, created_at, updated_at)
            select lpad(g::text, 8, '0'), 'Estudiante '||g, 'Sintetico '||(g % 97), 'activo', now(), now()
            from generate_series(1, 5000) g
            SQL);

        DB::statement(<<<'SQL'
            insert into matriculas (estudiante_id, curso_id, fecha_matricula, estado, monto_pagado, created_at, updated_at, deleted_at)
            select e.id, c.id,
                (current_date - (((e.id * 7 + c.id * 13) % 1000))::int)::date,
                (array['pendiente', 'pagada', 'anulada'])[1 + ((e.id + c.id) % 3)],
                null, now(), now(),
                case when (e.id + c.id) % 50 = 0 then now() else null end
            from estudiantes e cross join cursos c
            SQL);
    }
}
