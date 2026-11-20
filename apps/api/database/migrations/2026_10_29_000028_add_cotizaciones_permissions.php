<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Lista propia: el seeder ya no define los permisos de cotizaciones (los retira la migración 000081). @var list<string> */
    private const PERMISOS = ['cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'cotizaciones.gestionar'];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::PERMISOS as $name) {
                $permission = Permission::findOrCreate($name, 'web');
                Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Conserva asignaciones: el seeder también proporciona estos permisos.
    }
};
