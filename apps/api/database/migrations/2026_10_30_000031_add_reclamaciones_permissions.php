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
        // Solo crea los permisos y los concede a admin; no resincroniza ningún rol ni toca otros permisos.
        DB::transaction(function (): void {
            $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
            foreach (RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS as $name) {
                $permission = Permission::findOrCreate($name, 'web');
                $admin?->givePermissionTo($permission);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Conserva asignaciones: el seeder también proporciona estos permisos.
    }
};
