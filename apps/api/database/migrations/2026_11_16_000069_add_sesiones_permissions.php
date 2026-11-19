<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 5: permisos de sesiones de clase. `admin` y `coordinador` todos (incluido `sesiones.ver-enlace`); `secretaria` solo
 * `sesiones.ver`. No resincroniza ningún rol ni retira permisos concedidos a mano; un rol base inexistente se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::SESIONES_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => RolesAndPermissionsSeeder::SESIONES_PERMISSIONS,
                'coordinador' => RolesAndPermissionsSeeder::SESIONES_PERMISSIONS,
                'secretaria' => ['sesiones.ver'],
            ];
            foreach ($concesiones as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', RolesAndPermissionsSeeder::SESIONES_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
