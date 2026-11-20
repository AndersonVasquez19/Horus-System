<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convocatorias y horarios del panel académico (Ola 3, docs/ola3-diseno-datos.md §4.1).
 *
 * Una convocatoria es una edición abierta de un curso (fechas, cupos, horario); el curso sigue siendo el catálogo. Los
 * `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio). Sin borrados en cascada: un curso
 * con convocatorias no se elimina y los horarios no se borran con su convocatoria. El código `CNV-AAAA-NNNN` sale de
 * `secuencias_codigo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convocatorias', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 20)->unique();
            $table->foreignId('curso_id')->constrained('cursos')->restrictOnDelete();
            $table->string('nombre', 160)->nullable();
            $table->string('modalidad', 15);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedSmallInteger('cupos_maximos');
            $table->unsignedSmallInteger('cupos_disponibles');
            $table->string('estado', 12)->default('borrador');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'fecha_inicio']);
            $table->index('curso_id');
        });

        Schema::create('convocatoria_horarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('lugar', 120)->nullable();
            $table->string('docente', 120)->nullable();
            $table->timestamps();

            $table->index(['convocatoria_id', 'dia_semana']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table convocatorias add constraint convocatorias_estado_valido check (estado in ('borrador', 'abierta', 'en_curso', 'cerrada', 'cancelada'))");
            DB::statement("alter table convocatorias add constraint convocatorias_modalidad_valida check (modalidad in ('presencial', 'semipresencial', 'virtual', 'hibrido'))");
            DB::statement('alter table convocatorias add constraint convocatorias_fechas_coherentes check (fecha_fin >= fecha_inicio)');
            DB::statement('alter table convocatorias add constraint convocatorias_cupos_validos check (cupos_maximos >= 1 and cupos_disponibles >= 0 and cupos_disponibles <= cupos_maximos)');
            DB::statement('alter table convocatoria_horarios add constraint convocatoria_horarios_dia_valido check (dia_semana between 1 and 7)');
            DB::statement('alter table convocatoria_horarios add constraint convocatoria_horarios_horas_coherentes check (hora_fin > hora_inicio)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('convocatoria_horarios');
        Schema::dropIfExists('convocatorias');
    }
};
