<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
            foreach (RolesAndPermissionsSeeder::SOLICITUDES_MATRICULA_PERMISSIONS as $name) {
                $permiso = Permission::findOrCreate($name, 'web');
                $admin?->givePermissionTo($permiso);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Conserva las asignaciones; estos permisos también pertenecen a la matriz del seeder.
    }
};
