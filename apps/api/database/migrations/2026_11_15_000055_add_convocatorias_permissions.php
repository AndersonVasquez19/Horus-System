<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del módulo de convocatorias y horarios del panel académico (Ola 3).
 *
 * Crea los permisos y los concede a `admin` y `coordinador` (todos) y a `secretaria` (solo `convocatorias.ver`), como en
 * una instalación nueva. No resincroniza ningún rol ni retira permisos concedidos a mano. Un rol base inexistente se
 * omite sin crearlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS,
                'coordinador' => RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS,
                'secretaria' => ['convocatorias.ver'],
            ];
            foreach ($concesiones as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retirar los permisos solo cierra el acceso al módulo: no afecta a datos. Spatie elimina en cascada sus
        // asignaciones a roles y usuarios.
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
