<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de Reclamaciones, Fase 2 (Sprint 20): cuatro tablas del diseño definitivo (PROJECT_CONTEXT, Fase 1.2 §5).
 *
 * Las restricciones de coherencia se declaran como CHECK en PostgreSQL y como triggers equivalentes en SQLite
 * (mismo patrón que 2026_10_29_000027). El historial es de solo inserción: un trigger bloquea UPDATE y DELETE,
 * con la única excepción de que la FK `actor_id` pase a NULL cuando se elimina la cuenta del actor.
 * Solo se ha ejecutado en SQLite en memoria; el DDL de PostgreSQL no está probado y requiere revisión previa.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'reclamaciones' => [
            'reclamacion_canal_hoja' => "(canal = 'web' AND numero_hoja_respaldo IS NULL) OR (canal = 'respaldo' AND numero_hoja_respaldo IS NOT NULL)",
            'reclamacion_suspension_coherente' => '(suspension_desde IS NULL AND suspension_hasta IS NULL) OR (suspension_desde IS NOT NULL AND suspension_hasta IS NOT NULL AND suspension_hasta >= suspension_desde)',
            'reclamacion_menor_representante' => 'es_menor = false OR (representante_nombre IS NOT NULL AND representante_domicilio IS NOT NULL AND representante_telefono IS NOT NULL AND representante_email IS NOT NULL)',
            'reclamacion_bien_par' => '(bien_tipo IS NULL AND bien_descripcion IS NULL) OR (bien_tipo IS NOT NULL AND bien_descripcion IS NOT NULL)',
            'reclamacion_reclamo_con_bien' => "tipo <> 'reclamo' OR bien_tipo IS NOT NULL",
            'reclamacion_monto_no_negativo' => 'monto_bien IS NULL OR monto_bien >= 0',
            'reclamacion_respuesta_coherente' => "(estado = 'respondido' AND respuesta IS NOT NULL AND respondida_at IS NOT NULL) OR (estado <> 'respondido' AND respuesta IS NULL AND respondida_at IS NULL)",
        ],
        'reclamacion_envios' => [
            'reclamacion_envio_intentos' => 'intentos >= 0',
            'reclamacion_envio_copia_por_correo' => "tipo <> 'copia_hoja' OR canal = 'correo'",
            'reclamacion_envio_propuesta_por_correo' => "tipo <> 'propuesta' OR canal = 'correo'",
            'reclamacion_envio_enviado_fecha' => "estado <> 'enviado' OR enviado_at IS NOT NULL",
        ],
    ];

    public function up(): void
    {
        Schema::create('reclamaciones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('numero', 14)->unique();
            $table->char('token_envio', 64)->unique();
            $table->enum('canal', ['web', 'respaldo'])->default('web');
            $table->string('numero_hoja_respaldo', 20)->nullable();
            $table->enum('estado', ['abierto', 'respondido', 'concluido_por_acuerdo'])->default('abierto');
            $table->timestampTz('recibida_at');
            $table->date('fecha_limite');
            $table->date('suspension_desde')->nullable();
            $table->date('suspension_hasta')->nullable();
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->enum('tipo_documento', ['dni', 'ce', 'pasaporte', 'ruc']);
            $table->string('numero_documento', 20);
            $table->string('domicilio', 255);
            $table->string('telefono', 30);
            $table->string('email', 254);
            $table->enum('canal_respuesta', ['correo', 'carta'])->default('correo');
            $table->boolean('es_menor')->default(false);
            $table->string('representante_nombre', 120)->nullable();
            $table->string('representante_domicilio', 255)->nullable();
            $table->string('representante_telefono', 30)->nullable();
            $table->string('representante_email', 254)->nullable();
            $table->enum('tipo', ['reclamo', 'queja']);
            $table->enum('bien_tipo', ['producto', 'servicio'])->nullable();
            $table->string('bien_descripcion', 500)->nullable();
            $table->decimal('monto_bien', 12, 2)->nullable();
            $table->text('detalle');
            $table->text('pedido');
            $table->text('respuesta')->nullable();
            $table->timestampTz('respondida_at')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('privacidad_version', 20);
            $table->timestampTz('informado_at');
            $table->timestampsTz();
            $table->index(['estado', 'fecha_limite']);
            $table->index('responsable_id');
            $table->index('recibida_at');
            $table->index('numero_documento');
        });

        Schema::create('reclamacion_contadores', function (Blueprint $table): void {
            $table->smallInteger('anio')->primary();
            $table->unsignedInteger('ultimo')->default(0);
        });

        Schema::create('reclamacion_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reclamacion_id')->constrained('reclamaciones')->restrictOnDelete();
            $table->enum('evento', [
                'registrada', 'propuesta_enviada', 'propuesta_aceptada', 'propuesta_rechazada', 'propuesta_vencida',
                'respuesta_formal_registrada', 'correo_enviado', 'carta_registrada', 'correo_fallido',
                'responsable_asignado', 'tipo_reclasificado', 'nota_interna', 'hoja_respaldo_registrada',
            ]);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_nombre', 120);
            $table->json('metadatos');
            $table->text('nota')->nullable();
            $table->timestampTz('created_at');
            $table->index(['reclamacion_id', 'id']);
            $table->index('actor_id');
        });

        Schema::create('reclamacion_envios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reclamacion_id')->constrained('reclamaciones')->restrictOnDelete();
            $table->enum('tipo', ['copia_hoja', 'propuesta', 'respuesta']);
            $table->enum('canal', ['correo', 'carta'])->default('correo');
            $table->uuid('clave')->unique();
            $table->enum('estado', ['pendiente', 'procesando', 'enviado', 'fallido'])->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->string('asunto', 200);
            $table->text('cuerpo');
            $table->timestampTz('enviado_at')->nullable();
            $table->string('proveedor_id', 100)->nullable();
            $table->string('constancia', 255)->nullable();
            $table->string('error_codigo', 40)->nullable();
            $table->timestampsTz();
            $table->index(['estado', 'id']);
            $table->index('reclamacion_id');
        });
        // Un solo envío por tipo y canal, salvo propuestas sucesivas (índice parcial, válido en PostgreSQL y SQLite).
        DB::statement("CREATE UNIQUE INDEX reclamacion_envio_tipo_canal_unico ON reclamacion_envios (reclamacion_id, tipo, canal) WHERE tipo <> 'propuesta'");

        foreach (self::CHECKS as $tabla => $reglas) {
            $columnas = Schema::getColumnListing($tabla);
            foreach ($reglas as $nombre => $condicion) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $tabla ADD CONSTRAINT $nombre CHECK ($condicion)");
                } elseif (DB::getDriverName() === 'sqlite') {
                    $nueva = $this->conPrefijoNew($condicion, $columnas);
                    foreach (['INSERT', 'UPDATE'] as $operacion) {
                        DB::unprepared("CREATE TRIGGER {$nombre}_{$operacion} BEFORE $operacion ON $tabla WHEN NOT ($nueva) BEGIN SELECT RAISE(ABORT, '$nombre'); END");
                    }
                }
            }
        }

        $this->historialInmutable();
    }

    public function down(): void
    {
        // Orden: trigger → tablas (dependencias de FK) → función. Sin CASCADE: si otro objeto dependiera de la
        // función, el rollback debe fallar en vez de eliminarlo en silencio.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS reclamacion_historial_inmutable ON reclamacion_historial');
        }
        Schema::dropIfExists('reclamacion_envios');
        Schema::dropIfExists('reclamacion_historial');
        Schema::dropIfExists('reclamacion_contadores');
        Schema::dropIfExists('reclamaciones');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reclamacion_historial_inmutable()');
        }
    }

    /**
     * Solo inserción. La única modificación admitida es que la FK `actor_id` pase a NULL (ON DELETE SET NULL
     * al eliminar la cuenta del actor); `actor_nombre` conserva la autoría.
     *
     * PostgreSQL: `CREATE OR REPLACE` porque `migrate:fresh`/`db:wipe` (PostgresBuilder::dropAllTables) solo
     * eliminan tablas: el trigger muere con la tabla pero la función queda en `public` y un `CREATE FUNCTION`
     * simple fallaría con 42723 en la siguiente reconstrucción. La función es exclusiva de este módulo.
     */
    private function historialInmutable(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reclamacion_historial_inmutable() RETURNS trigger AS $fn$
                BEGIN
                    IF TG_OP = 'UPDATE'
                       AND NEW.actor_id IS NULL AND OLD.actor_id IS NOT NULL
                       AND NEW.id = OLD.id AND NEW.reclamacion_id = OLD.reclamacion_id
                       AND NEW.evento = OLD.evento AND NEW.actor_nombre = OLD.actor_nombre
                       AND NEW.metadatos::text = OLD.metadatos::text
                       AND NEW.nota IS NOT DISTINCT FROM OLD.nota
                       AND NEW.created_at = OLD.created_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'reclamacion_historial es de solo insercion';
                END;
                $fn$ LANGUAGE plpgsql
                SQL);
            DB::unprepared('CREATE TRIGGER reclamacion_historial_inmutable BEFORE UPDATE OR DELETE ON reclamacion_historial FOR EACH ROW EXECUTE FUNCTION reclamacion_historial_inmutable()');

            return;
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reclamacion_historial_sin_delete BEFORE DELETE ON reclamacion_historial BEGIN SELECT RAISE(ABORT, 'reclamacion_historial es de solo insercion'); END");
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER reclamacion_historial_sin_update BEFORE UPDATE ON reclamacion_historial
                WHEN NOT (NEW.actor_id IS NULL AND OLD.actor_id IS NOT NULL
                    AND NEW.id = OLD.id AND NEW.reclamacion_id = OLD.reclamacion_id
                    AND NEW.evento = OLD.evento AND NEW.actor_nombre = OLD.actor_nombre
                    AND NEW.metadatos = OLD.metadatos AND NEW.nota IS OLD.nota
                    AND NEW.created_at = OLD.created_at)
                BEGIN SELECT RAISE(ABORT, 'reclamacion_historial es de solo insercion'); END
                SQL);
        }
    }

    /** @param list<string> $columnas */
    private function conPrefijoNew(string $condicion, array $columnas): string
    {
        // Los literales entre comillas no se tocan; solo los identificadores que son columnas de la tabla.
        $partes = preg_split("/('[^']*')/", $condicion, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($partes as $i => $parte) {
            if (str_starts_with($parte, "'")) {
                continue;
            }
            $partes[$i] = preg_replace_callback('/\b[a-z_]+\b/', fn (array $m): string => in_array($m[0], $columnas, true) ? 'NEW.'.$m[0] : $m[0], $parte) ?? $parte;
        }

        return implode('', $partes);
    }
};
