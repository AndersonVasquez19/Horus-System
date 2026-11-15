<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline de oportunidades del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.3).
 *
 * Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio). Sin borrados en cascada:
 * un cliente con oportunidades no se elimina. El historial guarda etapas e identificadores, sin textos libres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidades', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('prospecto_id')->nullable()->constrained('prospectos')->nullOnDelete();
            $table->foreignId('servicio_id')->nullable()->constrained('servicios_tecnologicos')->nullOnDelete();
            $table->foreignId('cotizacion_id')->nullable()->constrained('solicitudes_cotizacion')->nullOnDelete();
            $table->string('titulo', 160);
            $table->string('etapa', 15)->default('nueva');
            $table->decimal('monto_estimado', 12, 2)->default(0);
            $table->char('moneda', 3)->default('PEN');
            $table->unsignedSmallInteger('probabilidad')->default(10);
            $table->date('fecha_cierre_estimada')->nullable();
            $table->string('motivo_perdida', 255)->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['etapa', 'created_at']);
            $table->index('cliente_id');
            $table->index('responsable_id');
        });

        Schema::create('oportunidad_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oportunidad_id')->constrained('oportunidades')->restrictOnDelete();
            $table->string('etapa_anterior', 15)->nullable();
            $table->string('etapa_nueva', 15);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['oportunidad_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $etapas = "'nueva', 'calificada', 'propuesta', 'negociacion', 'ganada', 'perdida'";
            DB::statement("alter table oportunidades add constraint oportunidades_etapa_valida check (etapa in ({$etapas}))");
            DB::statement('alter table oportunidades add constraint oportunidades_probabilidad_valida check (probabilidad between 0 and 100)');
            DB::statement('alter table oportunidades add constraint oportunidades_monto_valido check (monto_estimado >= 0)');
            // El motivo existe si y solo si la oportunidad está perdida; cerrada_at, si y solo si está cerrada.
            DB::statement("alter table oportunidades add constraint oportunidades_motivo_perdida_coherente check ((etapa = 'perdida') = (motivo_perdida is not null))");
            DB::statement("alter table oportunidades add constraint oportunidades_cierre_coherente check ((etapa in ('ganada', 'perdida')) = (cerrada_at is not null))");
            DB::statement("alter table oportunidad_historial add constraint oportunidad_historial_etapa_nueva_valida check (etapa_nueva in ({$etapas}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad_historial');
        Schema::dropIfExists('oportunidades');
    }
};
