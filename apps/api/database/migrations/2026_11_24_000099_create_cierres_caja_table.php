<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cierre de caja diario (Fase 3.2): un cierre por fecha, inmutable (lo garantiza la restricción única y el modelo), con lo esperado
 * según el libro de pagos, lo declarado por quien cuenta el dinero y la diferencia. Aditiva y reversible.
 *
 * Permiso nuevo `pagos.cerrar_caja`: solo `admin` por defecto (igual que confirmar y reversar). No usa el seeder para conceder, porque
 * este resincroniza los roles base y descartaría permisos concedidos a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cierres_caja')) {
            Schema::create('cierres_caja', function (Blueprint $table): void {
                $table->id();
                $table->uuid('identificador')->unique();
                $table->date('fecha')->unique();
                $table->unsignedInteger('cantidad_pagos');
                $table->unsignedInteger('cantidad_reversos');
                $table->decimal('total_cobrado', 12, 2);
                $table->decimal('total_reversado', 12, 2);
                $table->decimal('total_esperado', 12, 2);
                $table->decimal('total_declarado', 12, 2);
                $table->decimal('diferencia', 12, 2);
                $table->json('detalle');
                $table->string('observacion', 500)->nullable();
                $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cerrado_en');
                $table->timestamps();
            });
        }

        DB::transaction(function (): void {
            Permission::findOrCreate('pagos.cerrar_caja', 'web');
            Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo('pagos.cerrar_caja');
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_caja');
        Permission::query()->where('guard_name', 'web')->where('name', 'pagos.cerrar_caja')->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
