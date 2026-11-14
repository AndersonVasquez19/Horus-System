<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Altas, cambios y bajas de roles con las reglas de protección administrativa (R4 y rol `admin`).
 *
 * Única implementación: la usan el Blade (`Admin\RoleController`) y el panel (`Api\Panel\Compartido\RolController`).
 * También centraliza qué permisos se muestran: los históricos sin módulo se ocultan, no se borran.
 */
final class GestionRoles
{
    /**
     * Permisos históricos que ya no corresponden a módulos disponibles o que ninguna ruta comprueba
     * (`seguimientos.*`, `asistente.entrenar`). Solo se ocultan: no se borran filas.
     */
    public const PERMISOS_RETIRADOS = [
        'procesos.ver',
        'procesos.gestionar',
        'seguimientos.ver',
        'seguimientos.crear',
        'seguimientos.editar',
        'seguimientos.eliminar',
        'asistente.entrenar',
    ];

    public function __construct(private readonly AdminProtection $adminProtection) {}

    /**
     * @param  list<string>  $permisos
     */
    public function crear(User $actor, string $nombre, array $permisos): Role
    {
        $permisos = array_values(array_unique($permisos));

        $id = $this->adminProtection->runAdministrative(function () use ($nombre, $actor, $permisos): int {
            // Creación exclusiva; el actor se relee bajo el bloqueo y un no administrador solo puede conceder
            // permisos que ya posee (R4c).
            $alcance = $this->adminProtection->actorVigente($actor, ['roles.crear'], 'rol.crear', Role::class, null);
            $this->adminProtection->assertCanCreateRole($alcance, $permisos, 'rol.crear');

            $role = Role::create(['name' => $nombre, 'guard_name' => 'web']);
            $role->syncPermissions($permisos);
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            AuditLog::record('role_created', Role::class, $role->id, [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ], $alcance['actor']->id);

            return (int) $role->getKey();
        });

        return Role::query()->findOrFail($id);
    }

    /**
     * @param  list<string>  $permisos
     */
    public function actualizar(User $actor, Role $role, string $nombre, array $permisos): void
    {
        $permisos = array_values(array_unique($permisos));

        $this->adminProtection->runAdministrative(function () use ($role, $nombre, $permisos, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['roles.editar'], 'rol.actualizar', Role::class, $role->getKey());

            // Se relee el rol bajo el bloqueo para decidir con datos vigentes.
            $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            // R4 (403) primero; después las reglas de integridad del rol `admin` (422).
            $this->adminProtection->assertCanUpdateRole($alcance, $role, $permisos, 'rol.actualizar');
            $this->adminProtection->assertRoleCanBeRenamed($role, $nombre);
            $this->adminProtection->assertRolePermissionsKeepAdministration($role, $permisos);

            $before = [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            $role->update(['name' => $nombre]);
            $role->syncPermissions($permisos);
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            $after = [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            if ($after !== $before) {
                AuditLog::record('role_updated', Role::class, $role->id, [
                    'before' => $before,
                    'after' => $after,
                ], $alcance['actor']->id);
            }
        });
    }

    public function eliminar(User $actor, Role $role): void
    {
        $this->adminProtection->runAdministrative(function () use ($role, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['roles.eliminar'], 'rol.eliminar', Role::class, $role->getKey());
            $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanDeleteRoleAuthorized($alcance, $role, 'rol.eliminar');
            $this->adminProtection->assertRoleCanBeDeleted($role);

            $role->delete();
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            AuditLog::record('role_deleted', Role::class, $role->id, [
                'name' => $role->name,
            ], $alcance['actor']->id);
        });
    }

    /**
     * Roles con sus permisos visibles, por nombre.
     *
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        return Role::with(['permissions' => fn ($query) => $query->whereNotIn('name', self::PERMISOS_RETIRADOS)])->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permisos(): Collection
    {
        return Permission::whereNotIn('name', self::PERMISOS_RETIRADOS)->orderBy('name')->get();
    }
}
