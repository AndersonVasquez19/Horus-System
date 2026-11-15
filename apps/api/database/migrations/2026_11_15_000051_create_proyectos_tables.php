<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proyectos del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.4) y la tabla de secuencias de códigos.
 *
 * Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio). Sin borrados en cascada:
 * un cliente con proyectos no se elimina. Una oportunidad da un solo proyecto (`UNIQUE`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contador por tipo y año (`PRY-2026-0001`). Se incrementa bajo bloqueo de fila dentro de la transacción del alta.
        Schema::create('secuencias_codigo', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 10);
            $table->unsignedSmallInteger('anio');
            $table->unsignedInteger('ultimo')->default(0);

            $table->unique(['tipo', 'anio']);
        });

        Schema::create('proyectos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 20)->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('oportunidad_id')->nullable()->unique()->constrained('oportunidades')->restrictOnDelete();
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->string('estado', 15)->default('planificado');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin_estimada')->nullable();
            $table->date('fecha_fin_real')->nullable();
            $table->decimal('presupuesto', 12, 2)->nullable();
            $table->char('moneda', 3)->default('PEN');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'created_at']);
            $table->index('cliente_id');
            $table->index('responsable_id');
        });

        Schema::create('proyecto_hitos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->string('nombre', 160);
            $table->unsignedSmallInteger('orden');
            $table->date('fecha_objetivo')->nullable();
            $table->timestamp('completado_at')->nullable();
            $table->timestamps();

            $table->index(['proyecto_id', 'orden']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table proyectos add constraint proyectos_estado_valido check (estado in ('planificado', 'en_curso', 'pausado', 'completado', 'cancelado'))");
            DB::statement('alter table proyectos add constraint proyectos_presupuesto_valido check (presupuesto is null or presupuesto >= 0)');
            DB::statement('alter table proyectos add constraint proyectos_fechas_coherentes check (fecha_fin_estimada is null or fecha_inicio is null or fecha_fin_estimada >= fecha_inicio)');
            // La fecha de fin real existe si y solo si el proyecto está completado.
            DB::statement("alter table proyectos add constraint proyectos_fin_real_coherente check ((estado = 'completado') = (fecha_fin_real is not null))");
            DB::statement('alter table secuencias_codigo add constraint secuencias_codigo_ultimo_valido check (ultimo >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_hitos');
        Schema::dropIfExists('proyectos');
        Schema::dropIfExists('secuencias_codigo');
    }
};
