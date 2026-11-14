<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\GestionUsuarios;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:usuarios.ver', only: ['index', 'show']),
            new Middleware('permission:usuarios.crear', only: ['create', 'store']),
            new Middleware('permission:usuarios.editar', only: ['edit', 'update']),
            new Middleware('permission:usuarios.eliminar', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $query = User::query()->with('roles');

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            BusquedaLike::contiene($query, ['name', 'email'], $busqueda);
        }

        $usuarios = $query
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.usuarios.index', [
            'usuarios' => $usuarios,
            'roles' => Role::orderBy('name')->get(),
            'busqueda' => $busqueda,
        ]);
    }

    public function show(User $user): RedirectResponse
    {
        return redirect()->route('admin.usuarios.edit', $user);
    }

    public function create(): View
    {
        return view('admin.usuarios.create', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(StoreUserRequest $request, GestionUsuarios $gestion): RedirectResponse
    {
        $data = $request->validated();
        $gestion->crear($request->user(), $data, $data['roles'] ?? []);

        return redirect()->route('admin.usuarios.index')->with('success', 'El usuario se registró correctamente.');
    }

    public function edit(User $user): View
    {
        $user->load('roles');

        return view('admin.usuarios.edit', [
            'usuario' => $user,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, GestionUsuarios $gestion): RedirectResponse
    {
        $data = $request->validated();
        $gestion->actualizar($request->user(), $user, $data, $data['roles'] ?? [], $request->has('roles'), $request->session()->getId());

        return redirect()->route('admin.usuarios.index')->with('success', 'Los datos del usuario se actualizaron correctamente.');
    }

    public function destroy(Request $request, User $user, GestionUsuarios $gestion): RedirectResponse
    {
        $gestion->eliminar($request->user(), $user);

        return redirect()->route('admin.usuarios.index')->with('success', 'El usuario se eliminó correctamente.');
    }
}
