<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte técnico del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.5): tickets y sus notas.
 *
 * Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio). Sin borrados en cascada:
 * un cliente con tickets no se elimina y las notas no se borran. El código `SOP-AAAA-NNNN` sale de `secuencias_codigo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_soporte', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 20)->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->nullOnDelete();
            $table->string('asunto', 160);
            $table->text('descripcion')->nullable();
            $table->string('prioridad', 10)->default('media');
            $table->string('estado', 20)->default('abierto');
            $table->string('canal', 12);
            $table->foreignId('asignado_a')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vence_at');
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'created_at']);
            $table->index(['estado', 'vence_at']);
            $table->index('cliente_id');
            $table->index('asignado_a');
        });

        Schema::create('ticket_notas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_soporte')->restrictOnDelete();
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('interna')->default(false);
            $table->text('texto');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table tickets_soporte add constraint tickets_soporte_estado_valido check (estado in ('abierto', 'en_progreso', 'en_espera_cliente', 'resuelto', 'cerrado'))");
            DB::statement("alter table tickets_soporte add constraint tickets_soporte_prioridad_valida check (prioridad in ('baja', 'media', 'alta', 'critica'))");
            DB::statement("alter table tickets_soporte add constraint tickets_soporte_canal_valido check (canal in ('telefono', 'correo', 'presencial', 'web'))");
            // Un ticket resuelto tiene fecha de resolución y uno cerrado, de cierre (y solo uno cerrado tiene fecha de cierre).
            DB::statement("alter table tickets_soporte add constraint tickets_soporte_resolucion_coherente check (estado <> 'resuelto' or resuelto_at is not null)");
            DB::statement("alter table tickets_soporte add constraint tickets_soporte_cierre_coherente check ((estado = 'cerrado') = (cerrado_at is not null))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notas');
        Schema::dropIfExists('tickets_soporte');
    }
};
