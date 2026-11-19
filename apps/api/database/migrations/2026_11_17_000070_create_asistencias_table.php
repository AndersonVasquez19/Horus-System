<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 6: asistencia por sesión y matrícula.
 *
 * - Única por (sesión, matrícula). Sin borrado lógico ni en cascada (`RESTRICT`): una asistencia equivocada se CORRIGE con motivo
 *   (queda en `motivo_correccion` y en la auditoría con los estados anterior y nuevo, nunca con el texto libre).
 * - `registrado_por` conserva a la persona que registró; si se elimina su cuenta queda en NULL.
 * - Los CHECK de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sesion_clase_id')->constrained('sesiones_clase')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('estado', 12);
            $table->string('observacion', 200)->nullable();
            $table->string('motivo_correccion', 200)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sesion_clase_id', 'matricula_id']);
            $table->index('matricula_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table asistencias add constraint asistencias_estado_valido check (estado in ('presente', 'ausente', 'tardanza', 'justificada'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
