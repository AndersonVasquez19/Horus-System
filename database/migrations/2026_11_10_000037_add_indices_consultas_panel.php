<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría del panel (Fase 2): índices para las consultas reales del panel y para claves foráneas que
 * PostgreSQL no indexa por sí solo (las usan los listados y el `nullOnDelete`/`cascadeOnDelete` al borrar).
 *
 * - audit_logs: orden del listado por `created_at`; filtro por usuario (`user_id`).
 * - notificaciones_internas: `visiblePara()` (personal: user_id + audiencia; general: user_id NULL + audiencia)
 *   y el contador de no leídas del inicio.
 * - aprobaciones_internas: contador de pendientes (`estado`) y FKs de solicitante/aprobador.
 * - matriculas: FK `curso_id` (el índice existente empieza por `estudiante_id`) y rango del mes / orden de
 *   reportes por `fecha_matricula`.
 * - chat_logs: listado reciente y filtro por intención en el probador administrativo.
 *
 * Solo añade índices: no cambia datos ni restricciones. Se omite cualquiera que ya exista. En PostgreSQL
 * `CREATE INDEX` (no concurrente) bloquea escrituras de la tabla mientras se construye: aplicar en una ventana
 * de mantenimiento y con `--path` mientras la migración 000022 siga suspendida. Probada solo en SQLite en memoria.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> tabla => [nombre del índice => columnas] */
    private const INDICES = [
        'audit_logs' => [
            'audit_logs_created_at_index' => ['created_at'],
            'audit_logs_user_id_index' => ['user_id'],
        ],
        'notificaciones_internas' => [
            'notificaciones_internas_destino_index' => ['user_id', 'audiencia', 'leida'],
        ],
        'aprobaciones_internas' => [
            'aprobaciones_internas_estado_index' => ['estado'],
            'aprobaciones_internas_solicitante_id_index' => ['solicitante_id'],
            'aprobaciones_internas_aprobador_id_index' => ['aprobador_id'],
        ],
        'matriculas' => [
            'matriculas_curso_id_index' => ['curso_id'],
            'matriculas_fecha_matricula_index' => ['fecha_matricula'],
        ],
        'chat_logs' => [
            'chat_logs_created_at_index' => ['created_at'],
            'chat_logs_intent_created_at_index' => ['intent_detectado', 'created_at'],
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
