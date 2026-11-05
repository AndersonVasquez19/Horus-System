<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_cotizacion', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 40)->unique();
            $table->timestampTz('recibida_at');
            $table->foreignId('servicio_id')->nullable()->constrained('servicios_tecnologicos')->restrictOnDelete();
            $table->uuid('servicio_uuid')->nullable();
            $table->string('servicio_nombre_historico', 120)->nullable();
            $table->text('necesidad_cliente');
            $table->string('contacto_nombre', 120);
            $table->string('contacto_email', 254)->nullable();
            $table->string('contacto_telefono', 30)->nullable();
            $table->enum('estado', ['recibida', 'en_revision'])->default('recibida');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ultimo_seguimiento_at')->nullable();
            $table->date('proximo_seguimiento')->nullable();
            $table->unsignedInteger('revision')->default(1);
            // Identidad técnica inmutable, conservada aunque se elimine la cuenta del operador.
            $table->unsignedBigInteger('creador_ambito');
            $table->string('creacion_clave_hash', 64);
            $table->string('creacion_huella', 64);
            $table->unique(['creador_ambito', 'creacion_clave_hash'], 'cotizaciones_creacion_unica');
            $table->timestampsTz();
            $table->index(['estado', 'id']);
            $table->index(['responsable_id', 'id']);
            $table->index(['servicio_uuid', 'id']);
            $table->index(['recibida_at', 'id']);
            $table->index(['proximo_seguimiento', 'id']);
            $table->index('servicio_id');
            $table->index('creado_por');
        });
        Schema::create('cotizacion_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_cotizacion')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->enum('evento', ['creada', 'informacion_actualizada', 'estado_modificado', 'responsable_asignado', 'seguimiento_registrado']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadatos');
            $table->text('nota')->nullable();
            $table->unsignedBigInteger('actor_ambito');
            $table->string('clave_hash', 64)->nullable();
            $table->string('huella', 64)->nullable();
            $table->timestampTz('created_at');
            $table->index('actor_id');
            $table->unique(['solicitud_id', 'revision'], 'cotizacion_historial_revision_unica');
            $table->unique(['solicitud_id', 'actor_ambito', 'clave_hash'], 'cotizacion_seguimiento_unico');
        });
        $checks = [
            'solicitudes_cotizacion' => [
                'cotizacion_revision_positiva' => 'revision >= 1',
                'cotizacion_contacto_minimo' => '(contacto_email IS NOT NULL AND length(trim(contacto_email)) > 0) OR (contacto_telefono IS NOT NULL AND length(trim(contacto_telefono)) > 0)',
                'cotizacion_servicio_snapshot' => '(servicio_id IS NULL AND servicio_uuid IS NULL AND servicio_nombre_historico IS NULL) OR (servicio_id IS NOT NULL AND servicio_uuid IS NOT NULL AND servicio_nombre_historico IS NOT NULL)',
            ],
            'cotizacion_historial' => ['cotizacion_historial_revision_positiva' => 'revision >= 1'],
        ];
        foreach ($checks as $table => $rules) {
            foreach ($rules as $name => $condition) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($condition)");
                } elseif (DB::getDriverName() === 'sqlite') {
                    $newCondition = preg_replace('/\b(revision|contacto_email|contacto_telefono|servicio_id|servicio_uuid|servicio_nombre_historico)\b/', 'NEW.$1', $condition);
                    foreach (['INSERT', 'UPDATE'] as $operation) {
                        DB::unprepared("CREATE TRIGGER {$name}_{$operation} BEFORE $operation ON $table WHEN NOT ($newCondition) BEGIN SELECT RAISE(ABORT, '$name'); END");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_historial');
        Schema::dropIfExists('solicitudes_cotizacion');
    }
};
