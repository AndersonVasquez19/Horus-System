<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de espera por edición (Fase 3.7). Sin permisos nuevos: ver = `convocatorias.ver`; agregar/quitar = `matriculas.crear`.
 * `activa` vale 1 solo mientras la persona espera (NULL después): la restricción única impide estar dos veces esperando en la misma
 * edición y es portable (SQLite y PostgreSQL tratan los NULL como distintos). Aditiva y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lista_espera')) {
            return;
        }

        Schema::create('lista_espera', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->foreignId('estudiante_id')->constrained('estudiantes')->restrictOnDelete();
            $table->string('estado', 15)->default('esperando');
            $table->unsignedTinyInteger('activa')->nullable()->default(1);
            $table->string('nota', 200)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();

            $table->unique(['convocatoria_id', 'estudiante_id', 'activa'], 'lista_espera_activa_unica');
            $table->index(['convocatoria_id', 'estado', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_espera');
    }
};
