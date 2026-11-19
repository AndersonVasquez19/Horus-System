<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 3: permisos de infraestructura (sedes, aulas y plataformas virtuales).
 *
 * Crea los permisos y los concede a `admin` y `coordinador` (ambos) y a `secretaria` (solo `infraestructura.ver`), como en
 * una instalación nueva. No resincroniza ningún rol ni retira permisos concedidos a mano; un rol base inexistente se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS,
                'coordinador' => RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS,
                'secretaria' => ['infraestructura.ver'],
            ];
            foreach ($concesiones as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retirar los permisos solo cierra el acceso al módulo: no afecta a datos. Spatie elimina en cascada sus asignaciones.
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
