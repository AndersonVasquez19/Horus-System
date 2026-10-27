<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reglas que impiden dejar el sistema sin administración.
 *
 * Toda operación que pueda quitar el rol `admin`, borrar un administrador o reducir los
 * permisos del rol `admin` debe ejecutarse dentro de {@see self::runExclusively()}: esa
 * función serializa las operaciones bloqueando la fila del rol `admin`, de modo que el
 * recuento de administradores se lee después de que otra operación concurrente haya terminado.
 * (En PostgreSQL `SELECT ... FOR UPDATE` bloquea de verdad; SQLite ignora el bloqueo.)
 */
class AdminProtection
{
    public const ROLE = 'admin';

    private const GUARD = 'web';

    /**
     * Permisos que el rol `admin` nunca puede perder desde el panel: sin ellos nadie podría
     * volver a gestionar usuarios ni roles. Cambiarlos requiere intervención deliberada.
     *
     * @var list<string>
     */
    public const CRITICAL_PERMISSIONS = [
        'usuarios.ver',
        'usuarios.editar',
        'usuarios.asignar-roles',
        'roles.ver',
        'roles.editar',
    ];

    /**
     * Ejecuta el callback en una transacción que serializa las operaciones sobre administradores.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runExclusively(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            Role::query()
                ->where('name', self::ROLE)
                ->where('guard_name', self::GUARD)
                ->lockForUpdate()
                ->first();

            return $callback();
        });
    }

    /**
     * Invalida la caché de permisos de Spatie cuando se CONFIRME la transacción en curso.
     *
     * Spatie (6.x) vacía su caché dentro de `syncPermissions()`, es decir, antes del commit. Si otra
     * petición vuelve a llenarla en ese intervalo, lee los permisos anteriores (el cambio aún no es
     * visible) y la caché queda obsoleta hasta que expire (24 h por defecto). Registrar la
     * invalidación con `DB::afterCommit()` la ejecuta una sola vez, ya confirmados los datos, y la
     * descarta si la transacción termina en rollback. Fuera de una transacción se ejecuta de inmediato.
     *
     * Llamar desde dentro de {@see self::runExclusively()}, después de modificar los permisos del rol.
     */
    public function forgetPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function activeAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($roles) => $roles
                ->where('name', self::ROLE)
                ->where('guard_name', self::GUARD))
            ->count();
    }

    public function isAdmin(User $user): bool
    {
        return $user->roles()
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->exists();
    }

    public function isAdminRole(Role $role): bool
    {
        return $role->name === self::ROLE && $role->guard_name === self::GUARD;
    }

    public function assertRoleCanBeRenamed(Role $role, string $newName): void
    {
        if ($this->isAdminRole($role) && $newName !== self::ROLE) {
            throw ValidationException::withMessages([
                'name' => 'El rol principal "admin" no se puede renombrar.',
            ]);
        }
    }

    public function assertRoleCanBeDeleted(Role $role): void
    {
        if ($this->isAdminRole($role)) {
            throw ValidationException::withMessages([
                'rol' => 'No se puede eliminar el rol principal de administración.',
            ]);
        }
    }

    /**
     * @param  list<string>  $permissions  Permisos que quedarían asignados al rol.
     */
    public function assertRolePermissionsKeepAdministration(Role $role, array $permissions): void
    {
        if (! $this->isAdminRole($role)) {
            return;
        }

        $missing = array_values(array_diff(self::CRITICAL_PERMISSIONS, $permissions));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'El rol "admin" no puede perder permisos de administración: '
                    .implode(', ', $missing).'.',
            ]);
        }
    }

    public function assertUserCanBeDeleted(User $target, ?User $actor): void
    {
        if ($actor !== null && $actor->is($target)) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes eliminar tu propio usuario.',
            ]);
        }

        if ($this->isAdmin($target) && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'usuario' => 'No se puede eliminar al último administrador del sistema.',
            ]);
        }
    }

    /**
     * @param  list<string>  $newRoles  Roles que tendría el usuario tras el cambio.
     */
    public function assertRoleChangeKeepsAdministration(User $target, array $newRoles, ?User $actor): void
    {
        if (! $this->isAdmin($target) || in_array(self::ROLE, $newRoles, true)) {
            return;
        }

        if ($actor !== null && $actor->is($target)) {
            throw ValidationException::withMessages([
                'roles' => 'No puedes retirarte a ti mismo el rol de administrador. Pídeselo a otro administrador.',
            ]);
        }

        if ($this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => 'No se puede retirar el rol de administrador al último administrador del sistema.',
            ]);
        }
    }
}
