<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AccionAdministrativaDenegada;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
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
 *
 * Sprint 21.1: además de no dejar el sistema sin administración, impide la escalada de privilegios.
 * Las decisiones de autorización NO usan la caché de Spatie (`can()`, `hasRole()`, `getAllPermissions()`):
 * leen las tablas pivote con consultas directas, bajo el bloqueo de {@see self::runAdministrative()}, y
 * releen al actor dentro de la transacción. Una denegación lanza {@see AccionAdministrativaDenegada}
 * (403 genérico) y su auditoría se escribe después del rollback.
 *
 * @phpstan-type Alcance array{actor: User, permisos: list<string>, admin: bool}
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
     * Igual que {@see self::runExclusively()}, pero registra la denegación (si la hay) DESPUÉS del
     * rollback y vuelve a lanzarla: la respuesta sigue siendo un 403 y la fila de auditoría sobrevive.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAdministrative(Closure $callback): mixed
    {
        try {
            return $this->runExclusively($callback);
        } catch (AccionAdministrativaDenegada $denegacion) {
            $denegacion->registrar();

            throw $denegacion;
        }
    }

    // ---- Autoridad efectiva (desde la base de datos, sin caché de Spatie) --------------------

    /**
     * Permisos efectivos del usuario (directos + por roles), leídos de las tablas pivote.
     *
     * @return list<string>
     */
    public function effectivePermissionNames(User $user): array
    {
        $tablas = $this->tablas();
        $clave = $this->claveModelo();
        $tipo = $user->getMorphClass();

        $directos = DB::table($tablas['model_has_permissions'].' as mhp')
            ->join($tablas['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $tipo)
            ->where('mhp.'.$clave, $user->getKey())
            ->where('p.guard_name', self::GUARD)
            ->pluck('p.name');

        $porRoles = DB::table($tablas['model_has_roles'].' as mhr')
            ->join($tablas['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->join($tablas['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'r.id')
            ->join($tablas['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $tipo)
            ->where('mhr.'.$clave, $user->getKey())
            ->where('r.guard_name', self::GUARD)
            ->where('p.guard_name', self::GUARD)
            ->pluck('p.name');

        return $this->nombres($directos->merge($porRoles));
    }

    /**
     * Nombres de los roles del usuario, leídos de la base de datos.
     *
     * @return list<string>
     */
    public function roleNamesOf(User $user): array
    {
        $tablas = $this->tablas();

        return $this->nombres(
            DB::table($tablas['model_has_roles'].' as mhr')
                ->join($tablas['roles'].' as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', $user->getMorphClass())
                ->where('mhr.'.$this->claveModelo(), $user->getKey())
                ->where('r.guard_name', self::GUARD)
                ->pluck('r.name'),
        );
    }

    /**
     * Permisos de cada rol indicado (clave: nombre del rol). Un rol inexistente no aparece en el mapa.
     *
     * @param  list<string>  $roles
     * @return array<string, list<string>>
     */
    public function rolePermissionNames(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $tablas = $this->tablas();
        $mapa = [];

        $nombres = DB::table($tablas['roles'])
            ->where('guard_name', self::GUARD)
            ->whereIn('name', $roles)
            ->pluck('name');
        foreach ($nombres as $nombre) {
            $mapa[(string) $nombre] = [];
        }

        $filas = DB::table($tablas['roles'].' as r')
            ->join($tablas['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'r.id')
            ->join($tablas['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('r.guard_name', self::GUARD)
            ->where('p.guard_name', self::GUARD)
            ->whereIn('r.name', $roles)
            ->get(['r.name as rol', 'p.name as permiso']);

        foreach ($filas as $fila) {
            $mapa[(string) $fila->rol][] = (string) $fila->permiso;
        }

        return array_map(
            static fn (array $permisos): array => array_values(array_unique($permisos)),
            $mapa,
        );
    }

    /**
     * Relee al actor DENTRO de la transacción (con bloqueo) y exige que conserve ahora mismo los permisos
     * indicados. Cierra la carrera «el actor fue degradado mientras se procesaba su petición».
     *
     * @param  list<string>  $requeridos
     * @return Alcance
     */
    public function actorVigente(User $actor, array $requeridos, string $operacion, string $modelType, ?int $modelId): array
    {
        $vigente = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

        if (! $vigente instanceof User) {
            $this->deny($actor->getKey(), 'ACTOR', 'actor_sin_permiso_vigente', $operacion, $modelType, $modelId);
        }

        $permisos = $this->effectivePermissionNames($vigente);

        if (array_diff($requeridos, $permisos) !== []) {
            $this->deny($vigente->id, 'ACTOR', 'actor_sin_permiso_vigente', $operacion, $modelType, $modelId);
        }

        return ['actor' => $vigente, 'permisos' => $permisos, 'admin' => $this->isAdmin($vigente)];
    }

    // ---- Reglas sobre cuentas (R1, R1+, R3) -------------------------------------------------

    /**
     * R1 / R1+: un no administrador no modifica a un administrador ni a una cuenta con más poder que el
     * suyo. Cada cual puede editar su propio perfil (D-11); los roles propios los trata
     * {@see self::assertCanAssignRoles()}.
     *
     * @param  Alcance  $alcance
     */
    public function assertCanModifyUser(array $alcance, User $objetivo, string $operacion): void
    {
        $this->assertObjetivoEnAlcance($alcance, $objetivo, $operacion, 'admin_protegido_edicion', 'R1');
    }

    /**
     * R3 / R1+: solo un administrador elimina cuentas administrativas; tampoco se eliminan cuentas con más
     * poder que el actor. Las reglas de «no a uno mismo» y «último administrador» siguen en
     * {@see self::assertUserCanBeDeleted()}, que se evalúa después.
     *
     * @param  Alcance  $alcance
     */
    public function assertCanDeleteUser(array $alcance, User $objetivo, string $operacion): void
    {
        $this->assertObjetivoEnAlcance($alcance, $objetivo, $operacion, 'admin_protegido_eliminacion', 'R3');
    }

    /**
     * R2: exige `usuarios.asignar-roles` vigente cuando la petición trae roles (incluso sin cambios).
     *
     * @param  Alcance  $alcance
     */
    public function assertMayRequestRoles(array $alcance, bool $solicitado, string $operacion, ?int $modelId): void
    {
        if ($solicitado && ! in_array('usuarios.asignar-roles', $alcance['permisos'], true)) {
            $this->deny($alcance['actor']->id, 'R2d', 'sin_permiso_asignar_roles', $operacion, User::class, $modelId);
        }
    }

    /**
     * R2: reglas de asignación de roles para un actor que no es administrador.
     *
     * - R2c: nadie cambia sus propios roles.
     * - R2a: no se concede `admin`.
     * - R2b: cada rol añadido debe tener permisos contenidos en los efectivos del actor (techo de
     *   delegación, D-12). Como el objetivo ya está dentro del alcance del actor (R1+) y cada rol añadido
     *   también, la unión resultante no puede superar al actor: las combinaciones de roles no escalan.
     *
     * Las reglas 422 de «último administrador» siguen en {@see self::assertRoleChangeKeepsAdministration()}.
     *
     * @param  Alcance  $alcance
     * @param  list<string>  $actuales  Roles que tiene el objetivo (vacío al crear).
     * @param  list<string>  $nuevos  Roles que tendría tras la operación.
     */
    public function assertCanAssignRoles(array $alcance, ?User $objetivo, array $actuales, array $nuevos, string $operacion): void
    {
        $agregados = array_values(array_diff($nuevos, $actuales));

        if ($alcance['admin'] || ($agregados === [] && array_diff($actuales, $nuevos) === [])) {
            return;
        }

        $actor = $alcance['actor'];
        $modelId = $objetivo?->id;
        $contexto = ['roles_solicitados' => $nuevos, 'objetivo_es_actor' => $objetivo !== null && $actor->is($objetivo)];

        if ($objetivo !== null && $actor->is($objetivo)) {
            $this->deny($actor->id, 'R2c', 'autoasignacion_roles', $operacion, User::class, $modelId, $contexto);
        }

        if (in_array(self::ROLE, $agregados, true)) {
            $this->deny($actor->id, 'R2a', 'rol_admin_reservado', $operacion, User::class, $modelId, $contexto);
        }

        $mapa = $this->rolePermissionNames($agregados);
        $excedidos = [];

        foreach ($agregados as $rol) {
            if (! array_key_exists($rol, $mapa)) {
                // Rol inexistente en el guard web: se deniega (fail-closed).
                $this->deny($actor->id, 'R2b', 'rol_fuera_de_alcance', $operacion, User::class, $modelId, $contexto);
            }
            $excedidos = array_merge($excedidos, array_diff($mapa[$rol], $alcance['permisos']));
        }

        if ($excedidos !== []) {
            $this->deny($actor->id, 'R2b', 'rol_fuera_de_alcance', $operacion, User::class, $modelId, $contexto + [
                'permisos_excedidos' => array_values(array_unique($excedidos)),
            ]);
        }
    }

    // ---- Reglas sobre roles (R4) ------------------------------------------------------------

    /**
     * R4c: un rol nuevo solo puede incluir permisos que el actor ya posee.
     *
     * @param  Alcance  $alcance
     * @param  list<string>  $permisos
     */
    public function assertCanCreateRole(array $alcance, array $permisos, string $operacion): void
    {
        $excedidos = array_values(array_diff($permisos, $alcance['permisos']));

        if (! $alcance['admin'] && $excedidos !== []) {
            $this->deny($alcance['actor']->id, 'R4c', 'permisos_fuera_de_alcance', $operacion, Role::class, null, [
                'permisos_excedidos' => $excedidos,
            ]);
        }
    }

    /**
     * R4a/R4b/R4e/R4c: un no administrador no toca el rol `admin`, ni un rol que él mismo tiene, ni un rol
     * con permisos que él no posee; y solo AÑADE permisos que ya posee (se mide el delta, no el total).
     *
     * @param  Alcance  $alcance
     * @param  list<string>  $nuevosPermisos
     */
    public function assertCanUpdateRole(array $alcance, Role $rol, array $nuevosPermisos, string $operacion): void
    {
        if ($alcance['admin']) {
            return;
        }

        $actuales = $this->assertRolGestionable($alcance, $rol, $operacion);
        $excedidos = array_values(array_diff(array_diff($nuevosPermisos, $actuales), $alcance['permisos']));

        if ($excedidos !== []) {
            $this->deny($alcance['actor']->id, 'R4c', 'permisos_fuera_de_alcance', $operacion, Role::class, $rol->id, [
                'permisos_excedidos' => $excedidos,
            ]);
        }
    }

    /**
     * R4a/R4b/R4e: no se elimina el rol `admin`, un rol propio ni uno con permisos que el actor no posee.
     *
     * @param  Alcance  $alcance
     */
    public function assertCanDeleteRoleAuthorized(array $alcance, Role $rol, string $operacion): void
    {
        if (! $alcance['admin']) {
            $this->assertRolGestionable($alcance, $rol, $operacion);
        }
    }

    /**
     * @param  Alcance  $alcance
     * @return list<string> Permisos actuales del rol.
     */
    private function assertRolGestionable(array $alcance, Role $rol, string $operacion): array
    {
        $actor = $alcance['actor'];

        if ($this->isAdminRole($rol)) {
            $this->deny($actor->id, 'R4a', 'rol_admin_protegido', $operacion, Role::class, $rol->id);
        }

        if (in_array($rol->name, $this->roleNamesOf($actor), true)) {
            $this->deny($actor->id, 'R4b', 'rol_propio_no_editable', $operacion, Role::class, $rol->id);
        }

        $actuales = $this->rolePermissionNames([$rol->name])[$rol->name] ?? [];
        $excedidos = array_values(array_diff($actuales, $alcance['permisos']));

        if ($excedidos !== []) {
            $this->deny($actor->id, 'R4e', 'rol_fuera_de_alcance', $operacion, Role::class, $rol->id, [
                'permisos_excedidos' => $excedidos,
            ]);
        }

        return $actuales;
    }

    /**
     * @param  Alcance  $alcance
     */
    private function assertObjetivoEnAlcance(array $alcance, User $objetivo, string $operacion, string $motivoAdmin, string $reglaAdmin): void
    {
        $actor = $alcance['actor'];

        if ($alcance['admin'] || $actor->is($objetivo)) {
            return;
        }

        if ($this->isAdmin($objetivo)) {
            $this->deny($actor->id, $reglaAdmin, $motivoAdmin, $operacion, User::class, $objetivo->id, ['objetivo_es_admin' => true]);
        }

        $excedidos = array_values(array_diff($this->effectivePermissionNames($objetivo), $alcance['permisos']));

        if ($excedidos !== []) {
            $this->deny($actor->id, 'R1+', 'cuenta_fuera_de_alcance', $operacion, User::class, $objetivo->id, [
                'permisos_excedidos' => $excedidos,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function deny(?int $actorId, string $regla, string $motivo, string $operacion, string $modelType, ?int $modelId, array $contexto = []): never
    {
        throw new AccionAdministrativaDenegada($regla, $motivo, $operacion, $modelType, $modelId, $actorId, $contexto);
    }

    /**
     * @return array{roles: string, permissions: string, model_has_roles: string, model_has_permissions: string, role_has_permissions: string}
     */
    private function tablas(): array
    {
        return [
            'roles' => (string) config('permission.table_names.roles', 'roles'),
            'permissions' => (string) config('permission.table_names.permissions', 'permissions'),
            'model_has_roles' => (string) config('permission.table_names.model_has_roles', 'model_has_roles'),
            'model_has_permissions' => (string) config('permission.table_names.model_has_permissions', 'model_has_permissions'),
            'role_has_permissions' => (string) config('permission.table_names.role_has_permissions', 'role_has_permissions'),
        ];
    }

    private function claveModelo(): string
    {
        return (string) config('permission.column_names.model_morph_key', 'model_id');
    }

    /**
     * @param  Collection<array-key, mixed>  $coleccion
     * @return list<string>
     */
    private function nombres(Collection $coleccion): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $nombre): string => (string) $nombre,
            $coleccion->all(),
        )));
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
