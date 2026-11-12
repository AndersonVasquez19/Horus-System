<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extiende las restricciones CHECK de PostgreSQL a las tablas operativas que faltaban en la 000039:
 * - `aprobaciones_internas.estado` ({pendiente, aprobado, rechazado})
 * - `matricula_notifications.status` ({pending, retry, sending, accepted, failed, skipped, uncertain, held})
 * - `curso_publicaciones` (`vigente_hasta >= vigente_desde`, equivalente a `servicio_publicaciones`)
 *
 * Solo PostgreSQL: en SQLite es un no-op. Incluye preflight de compatibilidad de datos existentes,
 * es idempotente (`pg_constraint`) y tiene `down()` reversible. No aplicada en ninguna base real sin
 * autorización expresa.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{tabla: string, columna: string, valores: list<string>}>
     */
    public const VALORES = [
        'aprobaciones_internas_estado_valido' => [
            'tabla' => 'aprobaciones_internas',
            'columna' => 'estado',
            'valores' => ['pendiente', 'aprobado', 'rechazado'],
        ],
        'matricula_notifications_status_valido' => [
            'tabla' => 'matricula_notifications',
            'columna' => 'status',
            'valores' => ['pending', 'retry', 'sending', 'accepted', 'failed', 'skipped', 'uncertain', 'held'],
        ],
    ];

    public const VIGENCIA_CONSTRAINT = 'curso_publicaciones_vigencia_ordenada';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertExistingDataIsCompatible();

        foreach (self::sentencias() as $nombre => $definicion) {
            $existe = DB::selectOne(
                'select 1 as ok from pg_constraint where conname = ? and conrelid = ?::regclass',
                [$nombre, $definicion['tabla']]
            );
            if ($existe === null) {
                DB::statement($definicion['sql']);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::sentencias() as $nombre => $definicion) {
            DB::statement("alter table {$definicion['tabla']} drop constraint if exists {$nombre}");
        }
    }

    /**
     * @return array<string, array{tabla: string, sql: string}>
     */
    public static function sentencias(): array
    {
        $sentencias = [];
        foreach (self::VALORES as $nombre => $definicion) {
            $lista = implode(', ', array_map(static fn (string $valor): string => "'".$valor."'", $definicion['valores']));
            $sentencias[$nombre] = [
                'tabla' => $definicion['tabla'],
                'sql' => "alter table {$definicion['tabla']} add constraint {$nombre} check ({$definicion['columna']} in ({$lista}))",
            ];
        }

        $sentencias[self::VIGENCIA_CONSTRAINT] = [
            'tabla' => 'curso_publicaciones',
            'sql' => 'alter table curso_publicaciones add constraint '.self::VIGENCIA_CONSTRAINT
                .' check (vigente_hasta is null or vigente_desde is null or vigente_hasta >= vigente_desde)',
        ];

        return $sentencias;
    }

    private function assertExistingDataIsCompatible(): void
    {
        $problemas = [];

        foreach (self::VALORES as $definicion) {
            $consulta = DB::table($definicion['tabla'])->whereNotIn($definicion['columna'], $definicion['valores']);
            $total = (clone $consulta)->count();
            if ($total > 0) {
                $ids = $consulta->limit(20)->pluck('id')->implode(', ');
                $problemas[] = "{$definicion['tabla']}.{$definicion['columna']} tiene {$total} fila(s) fuera de [".implode(', ', $definicion['valores'])."] (ids: {$ids})";
            }
        }

        $invertidas = DB::table('curso_publicaciones')
            ->whereNotNull('vigente_desde')
            ->whereNotNull('vigente_hasta')
            ->whereColumn('vigente_hasta', '<', 'vigente_desde');
        $totalInvertidas = (clone $invertidas)->count();
        if ($totalInvertidas > 0) {
            $ids = $invertidas->limit(20)->pluck('id')->implode(', ');
            $problemas[] = "curso_publicaciones tiene {$totalInvertidas} fila(s) con vigente_hasta < vigente_desde (ids: {$ids})";
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "No se aplican las restricciones administrativas: hay datos incompatibles.\n - ".implode("\n - ", $problemas)
            );
        }
    }
};
