<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Separa dos operaciones sensibles de matrículas que reutilizaban otros permisos:
 *  - matriculas.exportar: exportación CSV (antes bastaba matriculas.ver).
 *  - matriculas.eliminar: borrado lógico (antes bastaba matriculas.anular).
 *
 * Mínimo privilegio: los nuevos permisos se asignan SOLO al rol `admin`. No se concede nada a
 * `coordinador` ni a `secretaria`; quien necesite estas acciones debe recibirlas de forma explícita.
 *
 * Es idempotente y no toca usuarios ni otros permisos. Se ejecuta con `php artisan migrate`
 * sobre una base ya sembrada; el seeder solo cubre instalaciones nuevas y entornos de prueba.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const PERMISSIONS = ['matriculas.exportar', 'matriculas.eliminar'];

    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $rolePermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $now = now();

        DB::transaction(function () use ($permissions, $roles, $rolePermissions, $now): void {
            foreach (self::PERMISSIONS as $name) {
                DB::table($permissions)->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $adminId = DB::table($roles)->where('name', 'admin')->where('guard_name', self::GUARD)->value('id');

            if ($adminId === null) {
                return;
            }

            $permissionIds = DB::table($permissions)
                ->whereIn('name', self::PERMISSIONS)
                ->where('guard_name', self::GUARD)
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table($rolePermissions)->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $adminId,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $rolePermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelPermissions = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        DB::transaction(function () use ($permissions, $rolePermissions, $modelPermissions): void {
            $ids = DB::table($permissions)
                ->whereIn('name', self::PERMISSIONS)
                ->where('guard_name', self::GUARD)
                ->pluck('id');

            DB::table($rolePermissions)->whereIn('permission_id', $ids)->delete();
            DB::table($modelPermissions)->whereIn('permission_id', $ids)->delete();
            DB::table($permissions)->whereIn('id', $ids)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
