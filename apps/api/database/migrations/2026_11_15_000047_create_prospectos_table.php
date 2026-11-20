<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prospectos del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.2).
 *
 * Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio), igual que en las
 * migraciones 000039 y 000041. Una solicitud de cotización da como máximo un prospecto (`UNIQUE`). Sin borrados en cascada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospectos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('nombre_contacto', 120);
            $table->string('empresa', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('origen', 20);
            $table->foreignId('servicio_interes_id')->nullable()->constrained('servicios_tecnologicos')->nullOnDelete();
            $table->string('estado', 15)->default('nuevo');
            $table->string('motivo_descarte', 255)->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('proximo_seguimiento')->nullable();
            $table->foreignId('solicitud_cotizacion_id')->nullable()->unique()->constrained('solicitudes_cotizacion')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'created_at']);
            $table->index('responsable_id');
            $table->index('cliente_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table prospectos add constraint prospectos_origen_valido check (origen in ('web', 'cotizacion', 'referido', 'evento', 'llamada', 'otro'))");
            DB::statement("alter table prospectos add constraint prospectos_estado_valido check (estado in ('nuevo', 'contactado', 'calificado', 'descartado', 'convertido'))");
            // El motivo existe si y solo si el prospecto está descartado; un convertido tiene cliente.
            DB::statement("alter table prospectos add constraint prospectos_motivo_descarte_coherente check ((estado = 'descartado') = (motivo_descarte is not null))");
            DB::statement("alter table prospectos add constraint prospectos_convertido_con_cliente check (estado <> 'convertido' or cliente_id is not null)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prospectos');
    }
};
