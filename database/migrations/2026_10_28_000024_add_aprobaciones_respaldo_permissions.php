<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lleva a bases ya sembradas los permisos de Aprobaciones y Respaldo que `RolesAndPermissionsSeeder`
 * define y los controladores exigen, pero que ninguna migración creaba (Sprint 15-B). Sin ellos,
 * `permission:` responde 403 a todos los roles, incluido `admin`.
 *
 * Reproduce exactamente el reparto del seeder para estos cuatro permisos y no toca ningún otro:
 * no resincroniza roles, no quita asignaciones y no afecta a usuarios. Idempotente.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    /** @var array<string, list<string>> permiso => roles que lo reciben */
    private const GRANTS = [
        'aprobaciones.ver' => ['admin', 'coordinador', 'secretaria'],
        'aprobaciones.gestionar' => ['admin', 'coordinador'],
        'respaldo.ver' => ['admin', 'coordinador'],
        'respaldo.exportar' => ['admin'],
    ];

    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $rolePermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $now = now();

        DB::transaction(function () use ($permissions, $roles, $rolePermissions, $now): void {
            foreach (array_keys(self::GRANTS) as $name) {
                DB::table($permissions)->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds = DB::table($permissions)->where('guard_name', self::GUARD)
                ->whereIn('name', array_keys(self::GRANTS))->pluck('id', 'name');
            $roleIds = DB::table($roles)->where('guard_name', self::GUARD)->pluck('id', 'name');

            foreach (self::GRANTS as $permission => $grantedTo) {
                foreach ($grantedTo as $role) {
                    if (isset($roleIds[$role], $permissionIds[$permission])) {
                        DB::table($rolePermissions)->insertOrIgnore([
                            'permission_id' => $permissionIds[$permission],
                            'role_id' => $roleIds[$role],
                        ]);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sin efecto a propósito: en instalaciones nuevas el seeder crea estos mismos permisos, y borrarlos
     * dejaría Aprobaciones y Respaldo inaccesibles. Ante un problema, restaurar backup.
     */
    public function down(): void
    {
        //
    }
};
