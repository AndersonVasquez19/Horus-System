<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 6: permisos de asistencia. `admin` y `coordinador` todos; `secretaria` solo
 * `asistencias.ver`. No resincroniza ningún rol ni retira permisos concedidos a mano; un rol base inexistente se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::ASISTENCIAS_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => RolesAndPermissionsSeeder::ASISTENCIAS_PERMISSIONS,
                'coordinador' => RolesAndPermissionsSeeder::ASISTENCIAS_PERMISSIONS,
                'secretaria' => ['asistencias.ver'],
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
                ->whereIn('name', RolesAndPermissionsSeeder::ASISTENCIAS_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
