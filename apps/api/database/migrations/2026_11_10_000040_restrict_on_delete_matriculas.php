<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `matriculas.estudiante_id` y `matriculas.curso_id` se crearon con ON DELETE CASCADE: un DELETE físico de un
 * estudiante o curso (SQL directo, script, consola) borraría en silencio sus matrículas, que son el historial
 * de pagos. La aplicación solo borra lógicamente (SoftDeletes), así que esto no cambia su comportamiento; pasa
 * ambas claves a RESTRICT, como las tablas nuevas (publicaciones, cotizaciones, reclamaciones).
 *
 * `matricula_notifications.matricula_id` conserva CASCADE a propósito: el aviso forma parte de la matrícula.
 *
 * Solo PostgreSQL (SQLite no permite alterar claves foráneas). Cada cambio es una sola sentencia
 * (DROP + ADD CONSTRAINT con el mismo nombre), toma un bloqueo breve sobre `matriculas` y la tabla padre, y es
 * idempotente: omite la clave que ya es RESTRICT. No toca datos. No aplicada en ninguna base: requiere
 * autorización expresa.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{columna: string, padre: string}>
     */
    public const CLAVES = [
        'matriculas_estudiante_id_foreign' => ['columna' => 'estudiante_id', 'padre' => 'estudiantes'],
        'matriculas_curso_id_foreign' => ['columna' => 'curso_id', 'padre' => 'cursos'],
    ];

    public function up(): void
    {
        $this->recrear('restrict', 'r');
    }

    public function down(): void
    {
        $this->recrear('cascade', 'c');
    }

    /**
     * SQL de cada clave para la acción indicada. Nombres y columnas salen solo de la constante CLAVES.
     *
     * @return array<string, string>
     */
    public static function sentencias(string $accion): array
    {
        $sentencias = [];
        foreach (self::CLAVES as $nombre => $clave) {
            $sentencias[$nombre] = "alter table matriculas drop constraint {$nombre}, "
                ."add constraint {$nombre} foreign key ({$clave['columna']}) references {$clave['padre']} (id) on delete {$accion}";
        }

        return $sentencias;
    }

    private function recrear(string $accion, string $codigo): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::sentencias($accion) as $nombre => $sql) {
            $actual = DB::selectOne(
                "select confdeltype::text as tipo from pg_constraint where conname = ? and conrelid = 'matriculas'::regclass",
                [$nombre]
            );
            if ($actual === null) {
                throw new RuntimeException("No existe la clave foránea {$nombre} en matriculas.");
            }
            if ($actual->tipo !== $codigo) {
                DB::statement($sql);
            }
        }
    }
};
