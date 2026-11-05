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
            foreach (RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS as $name) {
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
