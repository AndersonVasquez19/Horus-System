<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de entrada a los paneles de apps/web.
 *
 * Solo crea los dos permisos y los concede según la distribución aprobada (admin: ambos;
 * coordinador y secretaria: académico). No resincroniza ningún rol ni retira permisos concedidos a mano.
 * Un rol base inexistente se omite sin crearlo.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const DISTRIBUCION = [
        'admin' => [RolesAndPermissionsSeeder::PANEL_ACADEMICO, RolesAndPermissionsSeeder::PANEL_SERVICIOS],
        'coordinador' => [RolesAndPermissionsSeeder::PANEL_ACADEMICO],
        'secretaria' => [RolesAndPermissionsSeeder::PANEL_ACADEMICO],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (RolesAndPermissionsSeeder::PANEL_PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            foreach (self::DISTRIBUCION as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retirar los permisos de entrada solo cierra el acceso a los paneles de apps/web: no afecta a datos ni
        // a otros permisos. Spatie elimina en cascada sus asignaciones a roles y usuarios.
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', RolesAndPermissionsSeeder::PANEL_PERMISSIONS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
