<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horus Academic, Fase 2 (docs/horus-academic/02-diccionario-de-datos.md §A): vínculo OPCIONAL y ÚNICO entre un expediente de
 * estudiante y una cuenta de usuario, para habilitar un portal más adelante. No se habilita ningún portal ni endpoint de
 * estudiante con esta migración: la columna nace nula y ningún flujo existente la escribe.
 *
 * - Un usuario ↔ un expediente: índice único. Varias filas con `user_id` nulo son válidas (PostgreSQL y SQLite).
 * - `ON DELETE SET NULL` (y no `RESTRICT`): borrar una cuenta (flujo vigente de Usuarios) no debe fallar ni borrar el
 *   expediente académico; el expediente queda sin cuenta. Desvío deliberado del `RESTRICT` general del diseño, porque
 *   conserva el comportamiento existente de eliminación de usuarios.
 * - Aditiva y reversible; no toca datos ni otras columnas.
 *
 * SQLite: `Schema::table` con clave foránea reconstruye la tabla; se usa `ALTER TABLE … ADD COLUMN`, que no reconstruye
 * nada (mismo criterio que 000045 y 000057).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table estudiantes add column user_id integer null references users (id) on delete set null');
            DB::statement('create unique index estudiantes_user_id_unique on estudiantes (user_id)');

            return;
        }

        Schema::table('estudiantes', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite no puede quitar una columna con clave foránea sin reconstruir la tabla: solo es un entorno de pruebas,
            // donde la base se recrea entera.
            DB::statement('drop index if exists estudiantes_user_id_unique');

            return;
        }

        Schema::table('estudiantes', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
