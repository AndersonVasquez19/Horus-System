<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, caja del administrador: los permisos de pagos (`pagos.*`) quedan SOLO en `admin`.
 *
 * Retira `pagos.*` de `coordinador` y `secretaria` (decisión del propietario: el administrador registra y confirma todo el dinero).
 * Solo quita los permisos de pagos de esos dos roles; no toca ningún otro permiso ni a `admin`, y un rol inexistente se omite.
 * `down()` los devuelve como los había concedido la migración 000073.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['coordinador', 'secretaria'] as $rol) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->revokePermissionTo(RolesAndPermissionsSeeder::PAGOS_PERMISSIONS);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $concesiones = ['coordinador' => ['pagos.ver', 'pagos.planificar', 'pagos.registrar'], 'secretaria' => ['pagos.ver', 'pagos.registrar']];
            foreach ($concesiones as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
