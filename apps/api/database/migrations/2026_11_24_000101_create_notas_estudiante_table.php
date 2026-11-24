<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas de seguimiento de un estudiante (Fase 3.3). Solo se agregan (no se editan ni se borran) y no usan permisos nuevos:
 * ver = `estudiantes.ver`; escribir = `estudiantes.crear` o `estudiantes.editar`. Aditiva y reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notas_estudiante')) {
            return;
        }

        Schema::create('notas_estudiante', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('estudiante_id')->constrained('estudiantes')->restrictOnDelete();
            $table->string('texto', 500);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estudiante_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_estudiante');
    }
};
