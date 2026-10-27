<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'permission:roles.ver'])->only(['index']);
        $this->middleware(['auth', 'permission:roles.crear'])->only(['store']);
        $this->middleware(['auth', 'permission:roles.editar'])->only(['update']);
        $this->middleware(['auth', 'permission:roles.eliminar'])->only(['destroy']);
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
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,' . $role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $before = [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ];

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        AuditLog::record('role_updated', Role::class, $role->id, [
            'before' => $before,
            'after' => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ],
        ]);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se actualizó correctamente.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'admin') {
            return back()->withErrors(['rol' => 'No se puede eliminar el rol principal de administración.']);
        }

        $role->delete();

        AuditLog::record('role_deleted', Role::class, $role->id, [
            'name' => $role->name,
        ]);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se eliminó correctamente.');
    }
}
