<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Permisos de esta migración (propios: el seeder ya solo conserva `servicios.publicar`). @var list<string> */
    private const PERMISOS = ['servicios.ver', 'servicios.crear', 'servicios.editar', 'servicios.publicar'];

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
