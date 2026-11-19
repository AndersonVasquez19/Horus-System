<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina los módulos Docentes e Infraestructura de la base de datos: las tablas `convocatoria_docentes`, `docentes`, `aulas`,
 * `plataformas_virtuales` y `sedes`, y las columnas que las referenciaban (`convocatorias.sede_id|aula_id|plataforma_virtual_id|
 * exige_infraestructura`, `sesiones_clase.docente_id|aula_id|plataforma_virtual_id|enlace_virtual` y el texto libre
 * `convocatoria_horarios.lugar|docente`). Una clase queda con fecha, horas y tema.
 *
 * Seguridad: si alguna de esas tablas tiene filas, o alguna de esas columnas tiene valores, la migración se detiene sin cambiar
 * nada. No se borran datos por accidente; quien los necesite debe respaldarlos y vaciarlos a propósito. Los permisos los retira
 * la 000084.
 *
 * Orden en PostgreSQL: restricciones CHECK y clave compuesta → columnas (con sus claves foráneas e índices) → tablas, hijas primero.
 * SQLite (solo pruebas): quitar una columna con clave foránea reconstruye la tabla y borra sus `TRIGGER`; se guardan antes y se
 * recrean después.
 */
return new class extends Migration
{
    /** Hijas primero: ninguna tabla se elimina mientras otra tenga una clave foránea hacia ella. */
    private const TABLAS = ['convocatoria_docentes', 'docentes', 'aulas', 'plataformas_virtuales', 'sedes'];

    /** @var array<string, list<string>> */
    private const COLUMNAS = [
        'convocatorias' => ['sede_id', 'aula_id', 'plataforma_virtual_id', 'exige_infraestructura'],
        'sesiones_clase' => ['docente_id', 'aula_id', 'plataforma_virtual_id', 'enlace_virtual'],
        'convocatoria_horarios' => ['lugar', 'docente'],
    ];

    public function up(): void
    {
        $this->comprobarSinDatos();

        if (DB::getDriverName() === 'pgsql') {
            foreach (['convocatorias_infra_completa', 'convocatorias_aula_exige_sede', 'convocatorias_infra_excluyente', 'convocatorias_aula_de_la_sede'] as $restriccion) {
                DB::statement("alter table convocatorias drop constraint if exists {$restriccion}");
            }
            DB::statement('alter table sesiones_clase drop constraint if exists sesiones_clase_un_lugar');
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            $presentes = array_values(array_filter($columnas, static fn (string $c): bool => Schema::hasColumn($tabla, $c)));
            if ($presentes !== []) {
                $this->quitarColumnas($tabla, $presentes);
            }
        }

        foreach (self::TABLAS as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    public function down(): void
    {
        // No recrea nada a propósito: los módulos se retiraron y las tablas estaban vacías (la guarda de up() lo exige). Revertir
        // no debe fallar —las pruebas y `migrate:rollback` retroceden cadenas enteras—, pero tampoco resucita lo retirado. Para
        // recuperar la estructura, restaura el respaldo previo o aplica de nuevo las migraciones 000062, 000063, 000066 y 000068.
    }

    private function comprobarSinDatos(): void
    {
        $conDatos = [];
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasTable($tabla) && DB::table($tabla)->exists()) {
                $conDatos[] = $tabla;
            }
        }
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if ($columna === 'exige_infraestructura' || ! Schema::hasColumn($tabla, $columna)) {
                    continue;
                }
                if (DB::table($tabla)->whereNotNull($columna)->exists()) {
                    $conDatos[] = "{$tabla}.{$columna}";
                }
            }
        }
        if ($conDatos !== []) {
            throw new RuntimeException('No se eliminan Docentes e Infraestructura porque tienen datos: '.implode(', ', $conDatos).'. Respáldalos y vacíalos antes.');
        }
    }

    /**
     * Solo SQLite (pruebas). Se crea la tabla nueva con otro nombre, se copian los datos, se elimina la antigua y se renombra la
     * nueva. Así las claves foráneas de otras tablas (asistencias apunta al nombre `sesiones_clase`) no se reescriben a un nombre
     * temporal: renombrar la tabla antigua sí las reescribiría.
     */
    private function reconstruirSesionesSqlite(): void
    {
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'sesiones_clase')->pluck('sql')->all();

        Schema::create('sesiones_clase_nueva', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador');
            $table->foreignId('convocatoria_id')->constrained('convocatorias')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('tema', 200)->nullable();
            $table->string('estado', 12)->default('programada');
            $table->string('motivo_cancelacion', 200)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        $columnas = 'id, identificador, convocatoria_id, numero, fecha, hora_inicio, hora_fin, tema, estado, motivo_cancelacion, revision, created_at, updated_at';
        DB::statement("insert into sesiones_clase_nueva ({$columnas}) select {$columnas} from sesiones_clase");
        Schema::drop('sesiones_clase');
        Schema::rename('sesiones_clase_nueva', 'sesiones_clase');
        Schema::table('sesiones_clase', function (Blueprint $table): void {
            $table->unique('identificador');
            $table->unique(['convocatoria_id', 'numero']);
            $table->index(['convocatoria_id', 'fecha']);
        });

        foreach ($triggers as $sql) {
            if (DB::table('sqlite_master')->where('type', 'trigger')->where('sql', $sql)->doesntExist()) {
                DB::unprepared((string) $sql);
            }
        }
    }

    /** @param list<string> $columnas */
    private function quitarColumnas(string $tabla, array $columnas): void
    {
        if (DB::getDriverName() === 'sqlite' && $tabla === 'sesiones_clase') {
            // Sus claves foráneas son restricciones de tabla: SQLite no deja quitar esas columnas, así que se reconstruye la tabla.
            $this->reconstruirSesionesSqlite();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $triggers =DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $tabla)->pluck('sql')->all();
            foreach (DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $tabla)->whereNotNull('sql')->pluck('name') as $indice) {
                foreach ($columnas as $columna) {
                    if (str_contains((string) $indice, $columna)) {
                        DB::statement('drop index if exists "'.$indice.'"');
                    }
                }
            }
            Schema::table($tabla, function (Blueprint $table) use ($columnas): void {
                $table->dropColumn($columnas);
            });
            foreach ($triggers as $sql) {
                if (DB::table('sqlite_master')->where('type', 'trigger')->where('sql', $sql)->doesntExist()) {
                    DB::unprepared((string) $sql);
                }
            }

            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($columnas): void {
            foreach ($columnas as $columna) {
                if (str_ends_with($columna, '_id')) {
                    $table->dropConstrainedForeignId($columna);
                } else {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
