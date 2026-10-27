<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a la base de datos las reglas de matrícula que hoy solo garantiza la aplicación
 * (regla de rematrícula confirmada; ver docs/postgresql-l2.2-c-integridad.md).
 *
 * - Una sola matrícula VIGENTE por estudiante y curso. Vigente = no anulada y no eliminada
 *   lógicamente: el mismo predicado que MatriculaController::assertNoActiveDuplicate() y las
 *   FormRequest (Matricula usa SoftDeletes). El historial anulado o eliminado se conserva.
 * - 0 <= cupos_disponibles <= cupos_maximos.
 * - Precio y monto pagado no negativos.
 *
 * Antes de crear nada comprueba los datos existentes. Si alguna fila viola una regla, la migración
 * se detiene con el detalle y no aplica ningún cambio (en PostgreSQL el DDL es transaccional).
 */
return new class extends Migration
{
    private const INDEX = 'matriculas_vigente_unica';

    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'cursos' => [
            'cursos_cupos_rango' => 'cupos_maximos >= 0 and cupos_disponibles >= 0 and cupos_disponibles <= cupos_maximos',
            'cursos_precio_no_negativo' => 'precio >= 0',
        ],
        'matriculas' => [
            'matriculas_monto_no_negativo' => 'monto_pagado is null or monto_pagado >= 0',
        ],
    ];

    public function up(): void
    {
        $this->assertExistingDataIsCompatible();

        // SQLite y PostgreSQL admiten índices únicos parciales: la regla también rige en la suite.
        DB::statement(
            'create unique index '.self::INDEX.' on matriculas (estudiante_id, curso_id) '
            ."where deleted_at is null and estado <> 'anulada'"
        );

        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite no admite ALTER TABLE ... ADD CONSTRAINT ... CHECK.
        }

        foreach (self::CHECKS as $tabla => $restricciones) {
            foreach ($restricciones as $nombre => $condicion) {
                DB::statement("alter table {$tabla} add constraint {$nombre} check ({$condicion})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::CHECKS as $tabla => $restricciones) {
                foreach (array_keys($restricciones) as $nombre) {
                    DB::statement("alter table {$tabla} drop constraint if exists {$nombre}");
                }
            }
        }

        DB::statement('drop index if exists '.self::INDEX);
    }

    /**
     * Mismas consultas de diagnóstico que la sección 3 del documento, en solo lectura.
     */
    private function assertExistingDataIsCompatible(): void
    {
        $problemas = [];

        $duplicados = DB::table('matriculas')
            ->select('estudiante_id', 'curso_id')
            ->selectRaw('count(*) as vigentes')
            ->whereNull('deleted_at')
            ->where('estado', '<>', 'anulada')
            ->groupBy('estudiante_id', 'curso_id')
            ->havingRaw('count(*) > 1')
            ->limit(20)
            ->get();
        foreach ($duplicados as $fila) {
            $problemas[] = "estudiante {$fila->estudiante_id} / curso {$fila->curso_id}: {$fila->vigentes} matrículas vigentes";
        }

        foreach (self::CHECKS as $tabla => $restricciones) {
            foreach ($restricciones as $nombre => $condicion) {
                $ids = DB::table($tabla)->whereRaw("not ({$condicion})")->limit(20)->pluck('id');
                if ($ids->isNotEmpty()) {
                    $problemas[] = "{$tabla} incumple {$nombre} (ids: {$ids->implode(', ')})";
                }
            }
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                'Datos incompatibles con las restricciones de matrícula; corrígelos antes de migrar: '
                .implode('; ', $problemas).'.'
            );
        }
    }
};
