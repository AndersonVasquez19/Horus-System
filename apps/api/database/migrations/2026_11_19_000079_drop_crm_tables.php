<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina las tablas del CRM comercial (clientes, prospectos, oportunidades, proyectos y soporte), cuyo panel se retiró el
 * 18/11/2026 y que ningún código vivo usa, junto con la columna `solicitudes_cotizacion.cliente_id` (migración 000045).
 *
 * **No toca `secuencias_codigo`**: la creó la migración 000051 junto con los proyectos, pero la usa `CodigoSecuencial` para los
 * códigos de las ediciones (convocatorias).
 *
 * Seguridad: si alguna de estas tablas tuviera filas, la migración se detiene sin cambiar nada. No se borran datos por
 * accidente; quien las necesite debe respaldarlas y vaciarlas a propósito.
 *
 * Los permisos del CRM (`clientes.*`, `prospectos.*`, …) se conservan aquí: los retira la migración 000080.
 *
 * SQLite (solo pruebas): quitar una columna con clave foránea reconstruye la tabla y borra los `TRIGGER` que hacen de `CHECK`
 * (migración 000027); se guardan antes y se recrean después, y la columna no puede quedar apuntando a una tabla inexistente.
 */
return new class extends Migration
{
    /** Hijas primero: ninguna tabla se elimina mientras otra tenga una clave foránea hacia ella. */
    private const TABLAS = [
        'ticket_notas', 'tickets_soporte', 'proyecto_hitos', 'proyectos', 'oportunidad_historial', 'oportunidades',
        'prospectos', 'cliente_contactos', 'clientes',
    ];

    public function up(): void
    {
        $conDatos = [];
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasTable($tabla) && DB::table($tabla)->exists()) {
                $conDatos[] = $tabla;
            }
        }
        if ($conDatos !== []) {
            throw new RuntimeException('No se eliminan las tablas del CRM porque tienen datos: '.implode(', ', $conDatos).'. Respáldalas y vacíalas antes.');
        }

        if (Schema::hasColumn('solicitudes_cotizacion', 'cliente_id')) {
            if (DB::getDriverName() === 'sqlite') {
                // Solo pruebas. SQLite no deja quitar una columna con clave foránea sin reconstruir la tabla, y la reconstrucción
                // borra los TRIGGER que hacen de CHECK (000027): se guardan antes y se vuelven a crear después.
                $triggers = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'solicitudes_cotizacion')->pluck('sql')->all();
                DB::statement('drop index if exists solicitudes_cotizacion_cliente_id_index');
                Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
                    $table->dropColumn('cliente_id');
                });
                foreach ($triggers as $sql) {
                    if (DB::table('sqlite_master')->where('type', 'trigger')->where('sql', $sql)->doesntExist()) {
                        DB::unprepared((string) $sql);
                    }
                }
            } else {
                Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('cliente_id');
                });
            }
        }

        foreach (self::TABLAS as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    public function down(): void
    {
        // No recrea nada a propósito: el CRM se retiró y las tablas estaban vacías (la guarda de up() lo exige). Revertir esta
        // migración no debe fallar —las pruebas y `migrate:rollback` retroceden cadenas enteras—, pero tampoco resucita un módulo
        // retirado. Para recuperar la estructura, restaura el respaldo previo o aplica de nuevo las migraciones 000044 a 000053.
        // (La 000051 también crea `secuencias_codigo`, que esta migración conserva.)
    }
};
