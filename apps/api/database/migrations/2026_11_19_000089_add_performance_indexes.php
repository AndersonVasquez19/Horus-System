<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices adicionales de consulta en auditoría, obligaciones de pago y notificaciones internas (Ola 2).
 *
 * Solo añade índices de forma idempotente (`Schema::hasIndex`), sin alterar datos ni reconstruir tablas.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private const INDICES = [
        'audit_logs' => [
            'audit_logs_model_type_model_id_index' => ['model_type', 'model_id'],
            'audit_logs_action_index' => ['action'],
        ],
        'obligaciones_pago' => [
            'obligaciones_pago_estado_index' => ['estado'],
        ],
        'notificaciones_internas' => [
            'notificaciones_internas_created_at_index' => ['created_at'],
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
