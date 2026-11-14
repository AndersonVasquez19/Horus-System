<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Services\GestionRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller implements HasMiddleware
{
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

    public function index(GestionRoles $gestion): View
    {
        return view('admin.roles.index', [
            'roles' => $gestion->roles(),
            'permisos' => $gestion->permisos(),
        ]);
    }

    public function store(StoreRoleRequest $request, GestionRoles $gestion): RedirectResponse
    {
        $data = $request->validated();
        $gestion->crear($request->user(), $data['name'], $data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se creó correctamente.');
    }

    public function update(UpdateRoleRequest $request, Role $role, GestionRoles $gestion): RedirectResponse
    {
        $data = $request->validated();
        $gestion->actualizar($request->user(), $role, $data['name'], $data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se actualizó correctamente.');
    }

    public function destroy(Request $request, Role $role, GestionRoles $gestion): RedirectResponse
    {
        $gestion->eliminar($request->user(), $role);

        return redirect()->route('admin.roles.index')->with('success', 'El rol se eliminó correctamente.');
    }
}
