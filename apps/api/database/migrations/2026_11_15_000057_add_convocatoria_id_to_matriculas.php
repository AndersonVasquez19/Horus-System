<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo opcional entre una matrícula y una convocatoria (Ola 3, docs/ola3-diseno-datos.md §4.1, parte B).
 *
 * Las matrículas existentes no se tocan: quedan sin convocatoria y siguen descontando del cupo del curso. Sin borrado en
 * cascada: una convocatoria con matrículas vinculadas no puede eliminarse físicamente.
 *
 * SQLite: `Schema::table` con una clave foránea reconstruye la tabla y borra los `TRIGGER` que hacen de `CHECK`, así que
 * allí se usa `ALTER TABLE … ADD COLUMN`, que no reconstruye nada (mismo criterio que la migración 000045).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table matriculas add column convocatoria_id integer null references convocatorias (id) on delete restrict');
            DB::statement('create index matriculas_convocatoria_id_index on matriculas (convocatoria_id)');

            return;
        }

        Schema::table('matriculas', function (Blueprint $table): void {
            $table->foreignId('convocatoria_id')->nullable()->constrained('convocatorias')->restrictOnDelete();
            $table->index('convocatoria_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite no puede quitar una columna con clave foránea sin reconstruir la tabla: solo es un entorno de
            // pruebas, donde la base se recrea entera.
            DB::statement('drop index if exists matriculas_convocatoria_id_index');

            return;
        }

        Schema::table('matriculas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('convocatoria_id');
        });
    }
};
