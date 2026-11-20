<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditoría del panel (Fase 2): la migración de índices funciona en instalación nueva y en una base ya
 * sembrada con datos, es idempotente y reversible, y no altera filas. Solo SQLite en memoria: no prueba
 * PostgreSQL (bloqueos de CREATE INDEX ni planes de consulta).
 */
class IndicesConsultasPanelMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = '2026_11_10_000037_add_indices_consultas_panel';

    /** @var array<string, list<string>> */
    private const ESPERADOS = [
        'audit_logs' => ['audit_logs_created_at_index', 'audit_logs_user_id_index'],
        'notificaciones_internas' => ['notificaciones_internas_destino_index'],
        'aprobaciones_internas' => [
            'aprobaciones_internas_estado_index',
            'aprobaciones_internas_solicitante_id_index',
            'aprobaciones_internas_aprobador_id_index',
        ],
        'matriculas' => ['matriculas_curso_id_index', 'matriculas_fecha_matricula_index'],
        'chat_logs' => ['chat_logs_created_at_index', 'chat_logs_intent_created_at_index'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    private function migracion(): object
    {
        return require base_path('database/migrations/'.self::MIGRACION.'.php');
    }

    private function assertIndices(bool $existen): void
    {
        foreach (self::ESPERADOS as $tabla => $nombres) {
            foreach ($nombres as $nombre) {
                $this->assertSame($existen, Schema::hasIndex($tabla, $nombre), "{$tabla}.{$nombre}");
            }
        }
    }

    public function test_instalacion_nueva_tiene_todos_los_indices(): void
    {
        $this->assertIndices(true);
    }

    public function test_base_ya_sembrada_conserva_los_datos_y_la_migracion_es_idempotente_y_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        DB::table('audit_logs')->insert(['action' => 'previo', 'created_at' => now()]);
        // Fila sintética de metadatos (sin texto real de conversación).
        DB::table('chat_logs')->insert([
            'session_id' => 's1', 'mensaje_usuario' => '', 'respuesta_bot' => '', 'intent_detectado' => 'saludo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $antes = [DB::table('audit_logs')->get()->toArray(), DB::table('chat_logs')->get()->toArray()];

        // Base anterior a la migración: sin los índices.
        $migracion = $this->migracion();
        $migracion->down();
        $this->assertIndices(false);

        $migracion->up();
        $migracion->up(); // idempotente: no falla si ya existen
        $this->assertIndices(true);

        $this->assertEquals($antes, [DB::table('audit_logs')->get()->toArray(), DB::table('chat_logs')->get()->toArray()]);

        // Un índice añadido a mano con el mismo nombre no impide ejecutar la migración.
        $migracion->down();
        Schema::table('matriculas', fn ($table) => $table->index(['curso_id'], 'matriculas_curso_id_index'));
        $migracion->up();
        $this->assertIndices(true);
    }
}
