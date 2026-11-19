<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 3: infraestructura académica (docs/horus-academic/02-diccionario-de-datos.md §B).
 *
 * - `sedes`: locales físicos. `aulas`: pertenecen a una sede y tienen capacidad. `plataformas_virtuales`: cataloga la
 *   herramienta externa (Meet, Zoom…); NO guarda credenciales ni claves de API ni enlaces.
 * - Sin borrados en cascada (`RESTRICT`): una sede con aulas no se elimina. Tampoco se borran: se desactivan (`estado`).
 * - Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio, como en 000056).
 * - Aditiva y reversible; no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 120);
            $table->string('direccion', 200);
            $table->string('ciudad', 80)->default('Cajamarca');
            $table->string('estado', 10)->default('activo');
            $table->timestamps();
        });

        Schema::create('aulas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('capacidad');
            $table->string('estado', 10)->default('activo');
            $table->timestamps();

            $table->unique(['sede_id', 'codigo']);
            // Apoya la clave foránea compuesta (sede_id, aula_id) de `convocatorias` (solo PostgreSQL).
            $table->unique(['sede_id', 'id']);
        });

        Schema::create('plataformas_virtuales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('nombre', 80)->unique();
            $table->string('tipo', 10);
            $table->string('estado', 10)->default('activo');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['sedes', 'aulas', 'plataformas_virtuales'] as $tabla) {
                DB::statement("alter table {$tabla} add constraint {$tabla}_estado_valido check (estado in ('activo', 'inactivo'))");
            }
            DB::statement('alter table aulas add constraint aulas_capacidad_valida check (capacidad >= 1)');
            DB::statement("alter table plataformas_virtuales add constraint plataformas_virtuales_tipo_valido check (tipo in ('meet', 'zoom', 'teams', 'otra'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aulas');
        Schema::dropIfExists('plataformas_virtuales');
        Schema::dropIfExists('sedes');
    }
};
