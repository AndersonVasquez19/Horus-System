<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de mejora del panel, Fase 2.4: índices para las consultas del tablero, Pagos y Matrículas.
 *
 * - `transacciones_pago (estado, fecha_pago)`: pagos por confirmar y cobrado del mes (filtran por estado y por rango de fecha).
 * - `cuotas_obligacion (vence)`: cuotas vencidas y por vencer.
 * - `matriculas (estado, fecha_matricula)`: matrículas pendientes y listados por estado ordenados por fecha.
 *
 * Solo añade índices, de forma idempotente (`Schema::hasIndex`) y reversible. No altera datos ni reconstruye tablas. En PostgreSQL con
 * mucho volumen, `CREATE INDEX` bloquea las escrituras de la tabla mientras se construye: aplícala en una ventana tranquila. Verificada
 * solo en SQLite en memoria (no mide el plan de ejecución de PostgreSQL).
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private const INDICES = [
        'transacciones_pago' => [
            'transacciones_pago_estado_fecha_pago_index' => ['estado', 'fecha_pago'],
        ],
        'cuotas_obligacion' => [
            'cuotas_obligacion_vence_index' => ['vence'],
        ],
        'matriculas' => [
            'matriculas_estado_fecha_matricula_index' => ['estado', 'fecha_matricula'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $pendientes = array_filter(
                $indices,
                static fn (string $nombre): bool => ! Schema::hasIndex($tabla, $nombre),
                ARRAY_FILTER_USE_KEY,
            );

            if ($pendientes === []) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($pendientes): void {
                foreach ($pendientes as $nombre => $columnas) {
                    $table->index($columnas, $nombre);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $existentes = array_filter(
                array_keys($indices),
                static fn (string $nombre): bool => Schema::hasIndex($tabla, $nombre),
            );

            if ($existentes === []) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($existentes): void {
                foreach ($existentes as $nombre) {
                    $table->dropIndex($nombre);
                }
            });
        }
    }
};
