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
            'roles' => Role::with('permissions')->orderBy('name')->get(),
            'permisos' => Permission::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        AuditLog::record('role_created', Role::class, $role->id, [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ]);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se creó correctamente.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        $this->adminProtection->runExclusively(function () use ($role, $data, $permissions): void {
            // Se relee el rol bajo el bloqueo para decidir con datos vigentes.
            $role = Role::query()->whereKey($role->getKey())->firstOrFail();

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
            ]);
        });

        return redirect()->route('admin.roles.index')->with('success', 'El rol se actualizó correctamente.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->adminProtection->runExclusively(function () use ($role): void {
            $role = Role::query()->whereKey($role->getKey())->firstOrFail();

            $this->adminProtection->assertRoleCanBeDeleted($role);

            $role->delete();
            $this->adminProtection->forgetPermissionCacheAfterCommit();

            AuditLog::record('role_deleted', Role::class, $role->id, [
                'name' => $role->name,
            ]);
        });

        return redirect()->route('admin.roles.index')->with('success', 'El rol se eliminó correctamente.');
    }
}
