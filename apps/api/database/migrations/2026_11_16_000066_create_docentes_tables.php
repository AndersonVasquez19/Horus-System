<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 4: docentes y su asignación a las ediciones (`convocatorias`).
 *
 * - `docentes`: identificados SOLO por DNI peruano (8 dígitos, texto, único; los eliminados lógicamente también ocupan el
 *   DNI, como en `estudiantes`). Soft delete justificado: conserva el historial de ediciones dictadas. `user_id` opcional y
 *   único para un futuro portal (sin endpoints hoy; `ON DELETE SET NULL`, como `estudiantes.user_id`).
 * - `convocatoria_docentes`: un docente puede dictar muchas ediciones y una edición tener varios docentes; a lo sumo UN
 *   principal por edición (índice único parcial, válido en PostgreSQL y SQLite). Sin borrado en cascada (`RESTRICT`).
 * - Que el mismo DNI no esté a la vez en `estudiantes` y `docentes` no se puede expresar con una restricción entre tablas:
 *   lo imponen los servicios bajo un bloqueo consultivo por DNI (ver `App\Support\DniUnico`).
 * - Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio, como en 000056).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docentes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('dni', 8)->unique();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('email', 150)->nullable()->unique();
            $table->string('telefono', 20)->nullable();
            $table->string('especialidad', 150)->nullable();
            $table->string('estado', 10)->default('activo');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('convocatoria_docentes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->foreignId('docente_id')->constrained('docentes')->restrictOnDelete();
            $table->string('rol', 10);
            $table->timestamps();

            $table->unique(['convocatoria_id', 'docente_id']);
            $table->index('docente_id');
        });

        // A lo sumo un docente principal por edición.
        DB::statement("create unique index convocatoria_docentes_un_principal on convocatoria_docentes (convocatoria_id) where rol = 'principal'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table docentes add constraint docentes_dni_valido check (dni ~ '^[0-9]{8}$')");
            DB::statement("alter table docentes add constraint docentes_estado_valido check (estado in ('activo', 'inactivo'))");
            DB::statement("alter table convocatoria_docentes add constraint convocatoria_docentes_rol_valido check (rol in ('principal', 'apoyo'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('convocatoria_docentes');
        Schema::dropIfExists('docentes');
    }
};
