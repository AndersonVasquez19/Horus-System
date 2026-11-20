<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 7: permisos de finanzas. `admin` todos; `coordinador` ver, planificar y registrar; `secretaria` ver y registrar.
 * Confirmar, reversar y exportar son solo de `admin` (no hay rol de tesorería). No resincroniza ningún rol ni retira permisos
 * concedidos a mano; un rol base inexistente se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::PAGOS_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => RolesAndPermissionsSeeder::PAGOS_PERMISSIONS,
                'coordinador' => ['pagos.ver', 'pagos.planificar', 'pagos.registrar'],
                'secretaria' => ['pagos.ver', 'pagos.registrar'],
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
                ->whereIn('name', RolesAndPermissionsSeeder::PAGOS_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
