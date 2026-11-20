<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Services\AdminProtection;
use App\Services\GestionRoles;
use App\Support\Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Models\Role;

/**
 * Roles y permisos para los dos paneles. Toda escritura pasa por `GestionRoles` (la implementación del Blade): un no
 * administrador solo concede permisos que ya posee, el rol `admin` conserva sus permisos de administración y los roles
 * base no se renombran ni se eliminan. Los permisos históricos sin módulo se ocultan, no se borran.
 */
final class RolController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly GestionRoles $gestion,
        private readonly AdminProtection $proteccion,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:roles.ver', only: ['index']),
            new Middleware('permission:roles.crear', only: ['store']),
            new Middleware('permission:roles.editar', only: ['update']),
            new Middleware('permission:roles.eliminar', only: ['destroy']),
        ];
    }

    public function index(): JsonResponse
    {
        $roles = $this->gestion->roles();
        $roles->loadCount('users');

        return response()->json([
            'data' => $roles->map(fn (Role $rol): array => $this->representar($rol))->values()->all(),
            'meta' => ['permisos' => $this->gestion->permisos()->pluck('name')->all()],
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rol = $this->gestion->crear($this->usuarioAutenticado($request), Entrada::texto($data['name']), Entrada::listaDeTexto($data['permissions'] ?? []));

        return response()->json([
            'message' => 'El rol se creó correctamente.',
            'data' => $this->representar($this->visible($rol)),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();
        $this->gestion->actualizar($this->usuarioAutenticado($request), $role, Entrada::texto($data['name']), Entrada::listaDeTexto($data['permissions'] ?? []));

        return response()->json([
            'message' => 'El rol se actualizó correctamente.',
            'data' => $this->representar($this->visible($role->refresh())),
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->gestion->eliminar($this->usuarioAutenticado($request), $role);

        return response()->json(['message' => 'El rol se eliminó correctamente.']);
    }

    private function visible(Role $rol): Role
    {
        return $rol->load(['permissions' => fn ($consulta) => $consulta->whereNotIn('name', GestionRoles::PERMISOS_RETIRADOS)])->loadCount('users');
    }

    /** @return array<string, mixed> */
    private function representar(Role $rol): array
    {
        return [
            'id' => $rol->id,
            'nombre' => $rol->name,
            // `principal`: el rol admin; `base`: roles con acceso al panel que no se renombran ni eliminan.
            'tipo' => $this->proteccion->isAdminRole($rol) ? 'principal' : ($this->proteccion->isBaseRole($rol) ? 'base' : 'personalizado'),
            'permisos' => $rol->permissions->pluck('name')->sort()->values()->all(),
            'usuarios' => (int) $rol->users_count,
        ];
    }
}
