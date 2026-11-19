<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 5: sesiones de clase de una edición (`convocatorias`).
 *
 * - Sin borrado lógico: una sesión que no se dicta se CANCELA con motivo y queda en el historial (la asistencia futura cuelga de ella).
 * - `enlace_virtual` guarda el enlace de la clase virtual CIFRADO (cast `encrypted`); por eso es `text` y no admite índices.
 * - `numero` es único por edición. Los CHECK de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio).
 * - Los cruces de aula y de docente no se pueden expresar con un índice único: los impone `GestionSesiones` bajo bloqueos consultivos
 *   por aula y por docente (`pg_advisory_xact_lock`, solo PostgreSQL; sin probar en SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_clase', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('tema', 200)->nullable();
            $table->foreignId('docente_id')->nullable()->constrained('docentes')->restrictOnDelete();
            $table->foreignId('aula_id')->nullable()->constrained('aulas')->restrictOnDelete();
            $table->foreignId('plataforma_virtual_id')->nullable()->constrained('plataformas_virtuales')->restrictOnDelete();
            $table->text('enlace_virtual')->nullable();
            $table->string('estado', 12)->default('programada');
            $table->string('motivo_cancelacion', 200)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['convocatoria_id', 'numero']);
            $table->index(['convocatoria_id', 'fecha']);
            $table->index(['aula_id', 'fecha']);
            $table->index(['docente_id', 'fecha']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table sesiones_clase add constraint sesiones_clase_horas_coherentes check (hora_fin > hora_inicio)');
            DB::statement("alter table sesiones_clase add constraint sesiones_clase_estado_valido check (estado in ('programada', 'realizada', 'cancelada'))");
            DB::statement('alter table sesiones_clase add constraint sesiones_clase_un_lugar check (aula_id is null or plataforma_virtual_id is null)');
            DB::statement("alter table sesiones_clase add constraint sesiones_clase_cancelada_con_motivo check (estado <> 'cancelada' or motivo_cancelacion is not null)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_clase');
    }
};
