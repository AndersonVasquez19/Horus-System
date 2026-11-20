<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo opcional entre una solicitud de cotización y un cliente (Ola 3, docs/ola3-diseno-datos.md §3.1).
 *
 * Las solicitudes existentes no se tocan: quedan sin cliente y conservan sus datos de contacto embebidos. Sin
 * borrado en cascada: un cliente con solicitudes vinculadas no puede eliminarse físicamente.
 *
 * SQLite: `Schema::table` con una clave foránea reconstruye la tabla y borra los `TRIGGER` que hacen de `CHECK`
 * (migración 000027), así que allí se usa `ALTER TABLE … ADD COLUMN`, que no reconstruye nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table solicitudes_cotizacion add column cliente_id integer null references clientes (id) on delete restrict');
            DB::statement('create index solicitudes_cotizacion_cliente_id_index on solicitudes_cotizacion (cliente_id)');

            return;
        }

        Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
            $table->foreignId('cliente_id')->nullable()->after('responsable_id')->constrained('clientes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite no puede quitar una columna con clave foránea sin reconstruir la tabla (y perder los triggers de
            // la 000027): solo es un entorno de pruebas, donde la base se recrea entera.
            DB::statement('drop index if exists solicitudes_cotizacion_cliente_id_index');

            return;
        }

        Schema::table('solicitudes_cotizacion', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cliente_id');
        });
    }
};
