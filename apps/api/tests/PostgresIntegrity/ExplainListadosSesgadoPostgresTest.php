<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Etapa C (14/11/2026) — planes de los listados del panel con datos SESGADOS y mayor volumen (375 855 matrículas
 * SINTÉTICAS). `ExplainListadosPostgresTest` usa datos uniformes (cada curso tiene los mismos alumnos); aquí el reparto
 * es el de un sistema real: un curso concentra casi todo, otro la mitad, quince tienen 50 y uno solo 5; el estado
 * `pagada` domina (≈ 90 %) y `anulada` es raro (0,1 %); el 80 % de las fechas cae en el último mes; 0,1 % está en la
 * papelera. Es el escenario donde el planificador suele elegir mal (índice por fecha leído hacia atrás hasta hallar 20
 * filas de un valor raro).
 *
 * Solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test). Los datos se generan con generate_series,
 * se analizan y se eliminan al terminar (TRUNCATE en finally). Es una prueba de EVIDENCIA: afirma recuentos, que cada
 * plan se puede obtener y que la limpieza ocurrió; imprime tiempo y nodos de cada consulta y marca las lentas (> 1 s).
 * No fija tiempos ni índices: la decisión de crear un índice se toma con la evidencia, en el informe y con autorización
 * aparte; esta prueba nunca crea índices.
 */
class ExplainListadosSesgadoPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const ESTUDIANTES = 250000;

    public function test_planes_de_los_listados_con_datos_sesgados(): void
    {
        try {
            $this->generarDatos();
            $total = (int) DB::table('matriculas')->count();
            $this->assertSame(375855, $total); // 250 000 + 125 000 + 17 × 50 + 5
            fwrite(STDOUT, "\n[sesgo] matrículas={$total}; curso1=".DB::table('matriculas')->where('curso_id', $this->curso('SINT-0001'))->count()
                .'; curso20='.DB::table('matriculas')->where('curso_id', $this->curso('SINT-0020'))->count()
                .'; anuladas='.DB::table('matriculas')->where('estado', 'anulada')->count()
                .'; papelera='.DB::table('matriculas')->whereNotNull('deleted_at')->count()."\n");

            foreach (['matriculas', 'estudiantes', 'cursos'] as $tabla) {
                DB::statement("ANALYZE {$tabla}");
            }

            $hot = $this->curso('SINT-0001');
            $medio = $this->curso('SINT-0002');
            $raro = $this->curso('SINT-0020');
            $orden = 'order by fecha_matricula desc, id desc';
            $consultas = [
                'S1 curso concentrado, página 1' => "select * from matriculas where deleted_at is null and curso_id = {$hot} {$orden} limit 20",
                'S2 curso mediano, página 1' => "select * from matriculas where deleted_at is null and curso_id = {$medio} {$orden} limit 20",
                'S3 curso raro (5 filas), página 1' => "select * from matriculas where deleted_at is null and curso_id = {$raro} {$orden} limit 20",
                'S4 estado raro (anulada), global' => "select * from matriculas where deleted_at is null and estado = 'anulada' {$orden} limit 20",
                'S5 estado dominante (pagada), global' => "select * from matriculas where deleted_at is null and estado = 'pagada' {$orden} limit 20",
                'S6 curso concentrado + estado raro' => "select * from matriculas where deleted_at is null and curso_id = {$hot} and estado = 'anulada' {$orden} limit 20",
                'S7 curso raro + estado dominante' => "select * from matriculas where deleted_at is null and curso_id = {$raro} and estado = 'pagada' {$orden} limit 20",
                'S8 curso concentrado, página profunda (offset 100000)' => "select * from matriculas where deleted_at is null and curso_id = {$hot} {$orden} limit 20 offset 100000",
                'S9 total del curso concentrado' => "select count(*) from matriculas where deleted_at is null and curso_id = {$hot}",
                'S10 total global' => 'select count(*) from matriculas where deleted_at is null',
                'S11 matrículas de un estudiante' => 'select * from matriculas where estudiante_id = 1234 and deleted_at is null order by id desc',
                'S12 papelera (0,1 %)' => 'select * from matriculas where deleted_at is not null order by fecha_matricula desc, id desc limit 20',
                'S13 búsqueda por DNI parcial (LIKE %x%)' => "select * from matriculas where deleted_at is null and exists (select 1 from estudiantes where matriculas.estudiante_id = estudiantes.id and estudiantes.deleted_at is null and (lower(dni) like '%004217%' or lower(nombres) like '%004217%' or lower(apellidos) like '%004217%')) {$orden} limit 20",
            ];

            $lentas = [];
            foreach ($consultas as $titulo => $sql) {
                try {
                    $filas = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql);
                } catch (QueryException $e) {
                    // Supera el statement_timeout de la conexión protegida (5 s): es un hallazgo, no un error de la prueba.
                    $this->assertSame('57014', $e->getCode(), 'Solo se tolera el tiempo máximo de sentencia.');
                    $lentas[] = $titulo.' (supera 5 s)';
                    fwrite(STDOUT, sprintf('[sesgado] %-58s  > 5000 ms (cancelada por statement_timeout)', $titulo).PHP_EOL);
                    DB::reconnect();

                    continue;
                }
                $this->assertNotEmpty($filas);
                $json = json_decode((string) ((array) $filas[0])['QUERY PLAN'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayHasKey('Execution Time', $json[0]);
                $ms = (float) $json[0]['Execution Time'];
                $nodos = [];
                $this->nodos($json[0]['Plan'], $nodos);
                if ($ms > 1000.0) {
                    $lentas[] = $titulo;
                }
                fwrite(STDOUT, sprintf("[sesgado] %-58s %9.2f ms  %s\n", $titulo, $ms, implode(' > ', array_unique($nodos))));
            }
            fwrite(STDOUT, '[sesgado] lentas (> 1 s): '.($lentas === [] ? 'ninguna' : implode('; ', $lentas))."\n");
        } finally {
            DB::statement('TRUNCATE matriculas, estudiantes, cursos RESTART IDENTITY CASCADE');
            $this->assertSame(0, (int) DB::table('matriculas')->count(), 'Los datos sintéticos se eliminaron.');
        }
    }

    private function curso(string $codigo): int
    {
        return (int) DB::table('cursos')->where('codigo', $codigo)->value('id');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $salida
     */
    private function nodos(array $plan, array &$salida): void
    {
        $etiqueta = (string) $plan['Node Type'];
        if (isset($plan['Index Name'])) {
            $etiqueta .= '['.$plan['Index Name'].']';
        } elseif (isset($plan['Relation Name'])) {
            $etiqueta .= '['.$plan['Relation Name'].']';
        }
        $salida[] = $etiqueta;
        foreach ($plan['Plans'] ?? [] as $hijo) {
            $this->nodos($hijo, $salida);
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
            from generate_series(1, 250000) g
            SQL);

        // Curso 1: todos; curso 2: la mitad; cursos 3-19: 50 alumnos; curso 20: 5. En tandas por rango de estudiantes:
        // la conexión protegida limita cada sentencia a 5 s (statement_timeout) y esa salvaguarda no se toca.
        for ($desde = 1; $desde <= self::ESTUDIANTES; $desde += 20000) {
            $this->insertarMatriculas($desde, min($desde + 19999, self::ESTUDIANTES));
        }
    }

    private function insertarMatriculas(int $desde, int $hasta): void
    {
        DB::statement(<<<'SQL'
            insert into matriculas (estudiante_id, curso_id, fecha_matricula, estado, monto_pagado, created_at, updated_at, deleted_at)
            select e.id, c.id,
                case when (e.id * 7 + c.id * 13) % 10 < 8
                    then (current_date - ((e.id * 7 + c.id * 13) % 30)::int)::date
                    else (current_date - (30 + (e.id * 7 + c.id * 13) % 970)::int)::date end,
                case when e.id % 1000 = 0 then 'anulada' when e.id % 10 = 1 then 'pendiente' else 'pagada' end,
                null, now(), now(),
                case when e.id % 1000 = 7 then now() else null end
            from estudiantes e
            join cursos c on (c.codigo = 'SINT-0001'
                or (c.codigo = 'SINT-0002' and e.id % 2 = 0)
                or (c.codigo between 'SINT-0003' and 'SINT-0019' and e.id <= 50)
                or (c.codigo = 'SINT-0020' and e.id <= 5))
            where e.id between ? and ?
            SQL, [$desde, $hasta]);
    }
}
