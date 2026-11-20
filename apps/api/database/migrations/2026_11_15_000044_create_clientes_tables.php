<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.1) y sus contactos.
 *
 * Los `CHECK` de PostgreSQL se añaden solo en ese motor (en SQLite los valida el servicio), igual que en las
 * migraciones 000039 y 000041. La unicidad `(documento_tipo, documento_numero)` solo aplica cuando hay documento:
 * los valores nulos no colisionan en ninguno de los dos motores. Sin borrados en cascada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identificador')->unique();
            $table->string('tipo', 10);
            $table->string('nombre', 160);
            $table->string('documento_tipo', 10)->nullable();
            $table->string('documento_numero', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('estado', 10)->default('activo');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['documento_tipo', 'documento_numero']);
            $table->index(['estado', 'nombre']);
            $table->index('responsable_id');
        });

        Schema::create('cliente_contactos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('cargo', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->index(['cliente_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table clientes add constraint clientes_tipo_valido check (tipo in ('persona', 'empresa'))");
            DB::statement("alter table clientes add constraint clientes_estado_valido check (estado in ('activo', 'inactivo'))");
            DB::statement("alter table clientes add constraint clientes_documento_tipo_valido check (documento_tipo is null or documento_tipo in ('dni', 'ruc', 'ce', 'otro'))");
            DB::statement('alter table clientes add constraint clientes_documento_completo check ((documento_tipo is null) = (documento_numero is null))');
            // Un solo contacto principal por cliente.
            DB::statement('create unique index cliente_contactos_un_principal on cliente_contactos (cliente_id) where principal');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_contactos');
        Schema::dropIfExists('clientes');
    }
};
