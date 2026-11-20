<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del módulo de proyectos del panel de servicios (Ola 3).
 *
 * Solo crea los permisos y los concede al rol `admin` (restricción comercial vigente). No resincroniza ningún rol
 * ni retira permisos concedidos a mano. Un rol base inexistente se omite sin crearlo.
 */
return new class extends Migration
{
    /** Lista propia: el seeder ya no define los permisos del CRM (los retira la migración 000080). @var list<string> */
    private const PERMISOS = ['proyectos.ver', 'proyectos.crear', 'proyectos.editar'];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::PERMISOS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()
                ?->givePermissionTo(self::PERMISOS);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retirar los permisos solo cierra el acceso al módulo: no afecta a datos. Spatie elimina en cascada sus
        // asignaciones a roles y usuarios.
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', self::PERMISOS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
