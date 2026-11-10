<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a la base de datos los estados y modalidades que hoy solo garantizan los FormRequest:
 * `estudiantes.estado`, `cursos.estado`, `cursos.modalidad`, `matriculas.estado` y
 * `curso_publicaciones.modalidad`. Las tablas nuevas (servicios, cotizaciones, reclamaciones) ya llevan
 * CHECK equivalentes.
 *
 * Solo PostgreSQL: SQLite no admite ADD CONSTRAINT ... CHECK y allí rige la validación de la aplicación.
 * Antes de crear nada cuenta las filas fuera del conjunto (incluidas las eliminadas lógicamente); si hay
 * alguna se detiene con tabla, columna, conteo e ids, sin datos personales, y no cambia nada (el DDL de
 * PostgreSQL es transaccional). Es idempotente: omite una restricción que ya exista.
 *
 * ADD CONSTRAINT ... CHECK toma ACCESS EXCLUSIVE mientras valida la tabla: aplicar en una ventana de poco
 * uso. Los conjuntos de VALORES deben coincidir con los de los FormRequest (lo comprueba
 * CheckEstadosAcademicosMigracionTest). No aplicada en ninguna base: requiere autorización expresa.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{tabla: string, columna: string, valores: list<string>}>
     */
    public const VALORES = [
        'matriculas_estado_valido' => ['tabla' => 'matriculas', 'columna' => 'estado', 'valores' => ['pendiente', 'pagada', 'anulada']],
        'cursos_estado_valido' => ['tabla' => 'cursos', 'columna' => 'estado', 'valores' => ['activo', 'inactivo', 'archivado']],
        'cursos_modalidad_valida' => ['tabla' => 'cursos', 'columna' => 'modalidad', 'valores' => ['presencial', 'semipresencial', 'virtual', 'hibrido']],
        'estudiantes_estado_valido' => ['tabla' => 'estudiantes', 'columna' => 'estado', 'valores' => ['activo', 'inactivo']],
        'curso_publicaciones_modalidad_valida' => ['tabla' => 'curso_publicaciones', 'columna' => 'modalidad', 'valores' => ['presencial', 'semipresencial', 'virtual', 'hibrido']],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertExistingDataIsCompatible();

        foreach (self::sentencias() as $nombre => $sql) {
            $existe = DB::selectOne(
                'select 1 as ok from pg_constraint where conname = ? and conrelid = ?::regclass',
                [$nombre, self::VALORES[$nombre]['tabla']]
            );
            if ($existe === null) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::VALORES as $nombre => $definicion) {
            DB::statement("alter table {$definicion['tabla']} drop constraint if exists {$nombre}");
        }
    }

    /**
     * SQL de cada restricción. Los nombres y valores salen solo de la constante VALORES.
     *
     * @return array<string, string>
     */
    public static function sentencias(): array
    {
        $sentencias = [];
        foreach (self::VALORES as $nombre => $definicion) {
            $lista = implode(', ', array_map(static fn (string $valor): string => "'".$valor."'", $definicion['valores']));
            $sentencias[$nombre] = "alter table {$definicion['tabla']} add constraint {$nombre} check ({$definicion['columna']} in ({$lista}))";
        }

        return $sentencias;
    }

    private function assertExistingDataIsCompatible(): void
    {
        $problemas = [];

        foreach (self::VALORES as $nombre => $definicion) {
            $consulta = DB::table($definicion['tabla'])->whereNotIn($definicion['columna'], $definicion['valores']);
            $total = (clone $consulta)->count();
            if ($total > 0) {
                $ids = $consulta->limit(20)->pluck('id')->implode(', ');
                $problemas[] = "{$definicion['tabla']}.{$definicion['columna']} tiene {$total} fila(s) fuera de [".implode(', ', $definicion['valores'])."] (ids: {$ids})";
            }
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "No se aplican las restricciones de estados: hay datos incompatibles.\n - ".implode("\n - ", $problemas)
            );
        }
    }
};
