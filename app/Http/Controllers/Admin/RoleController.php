<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AdminProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller implements HasMiddleware
{
    /** Permisos históricos que ya no corresponden a módulos disponibles. */
    private const PERMISOS_RETIRADOS = ['procesos.ver', 'procesos.gestionar'];

    public function __construct(private readonly AdminProtection $adminProtection) {}

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:roles.ver', only: ['index']),
            new Middleware('permission:roles.crear', only: ['store']),
            new Middleware('permission:roles.editar', only: ['update']),
            new Middleware('permission:roles.eliminar', only: ['destroy']),
        ];
    }

    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::with(['permissions' => fn ($query) => $query->whereNotIn('name', self::PERMISOS_RETIRADOS)])->orderBy('name')->get(),
            'permisos' => Permission::whereNotIn('name', self::PERMISOS_RETIRADOS)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $actor = $request->user();

        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        // Sprint 21.1: creación exclusiva; el actor se relee bajo el bloqueo y un no administrador solo puede
        // conceder permisos que ya posee (R4c).
        $this->adminProtection->runAdministrative(function () use ($data, $actor, $permissions): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['roles.crear'], 'rol.crear', Role::class, null);
            $this->adminProtection->assertCanCreateRole($alcance, $permissions, 'rol.crear');

            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            AuditLog::record('role_created', Role::class, $role->id, [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ], $alcance['actor']->id);
        });

        return redirect()->route('admin.roles.index')->with('success', 'El rol se creó correctamente.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $actor = $request->user();

        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        $this->adminProtection->runAdministrative(function () use ($role, $data, $permissions, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['roles.editar'], 'rol.actualizar', Role::class, $role->getKey());

            // Se relee el rol bajo el bloqueo para decidir con datos vigentes.
            $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            // R4 (403) primero; después las reglas de integridad del rol `admin` (422).
            $this->adminProtection->assertCanUpdateRole($alcance, $role, $permissions, 'rol.actualizar');
            $this->adminProtection->assertRoleCanBeRenamed($role, $data['name']);
            $this->adminProtection->assertRolePermissionsKeepAdministration($role, $permissions);

            $before = [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ];

            $role->update(['name' => $data['name']]);
            $role->syncPermissions($permissions);
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            AuditLog::record('role_updated', Role::class, $role->id, [
                'before' => $before,
                'after' => [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->all(),
                ],
            ], $alcance['actor']->id);
        });

        return redirect()->route('admin.roles.index')->with('success', 'El rol se actualizó correctamente.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $actor = $request->user();

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

        return redirect()->route('admin.roles.index')->with('success', 'El rol se eliminó correctamente.');
    }
}
