<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller implements HasMiddleware
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
            new Middleware('permission:usuarios.ver', only: ['index', 'show']),
            new Middleware('permission:usuarios.crear', only: ['create', 'store']),
            new Middleware('permission:usuarios.editar', only: ['edit', 'update']),
            new Middleware('permission:usuarios.eliminar', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $query = User::query()->with('roles');

        if ($request->filled('busqueda')) {
            $busqueda = trim((string) $request->input('busqueda'));
            $query->where(function ($query) use ($busqueda): void {
                $query->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('email', 'like', "%{$busqueda}%");
            });
        } else {
            $busqueda = '';
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        // Asignar roles concede permisos: exige su propio permiso además de usuarios.crear.
        abort_if(! empty($data['roles']) && ! $request->user()->can('usuarios.asignar-roles'), 403);

        $usuario = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        if (! empty($data['roles'])) {
            $usuario->syncRoles($data['roles']);
        }

        AuditLog::record('user_created', User::class, $usuario->id, [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'roles' => $usuario->roles->pluck('name')->all(),
        ]);

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

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        // Asignar roles concede permisos: exige su propio permiso además de usuarios.editar.
        $actor = $request->user();
        $canAssignRoles = $actor->can('usuarios.asignar-roles');
        abort_if($request->has('roles') && ! $canAssignRoles, 403);

        /** @var list<string> $newRoles */
        $newRoles = array_values(array_unique($data['roles'] ?? []));

        // Cambiar roles puede dejar al sistema sin administradores: se serializa y se valida bajo bloqueo.
        $this->adminProtection->runExclusively(function () use ($user, $data, $actor, $canAssignRoles, $newRoles): void {
            $user = User::query()->whereKey($user->getKey())->firstOrFail();

            if ($canAssignRoles) {
                $this->adminProtection->assertRoleChangeKeepsAdministration($user, $newRoles, $actor);
            }

            $previous = [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->all(),
            ];

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            if ($canAssignRoles) {
                $user->syncRoles($newRoles);
            }

            AuditLog::record('user_updated', User::class, $user->id, [
                'before' => $previous,
                'after' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->all(),
                ],
            ], $actor->id);
        });

        return redirect()->route('admin.usuarios.index')->with('success', 'Los datos del usuario se actualizaron correctamente.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        $this->adminProtection->runExclusively(function () use ($user, $actor): void {
            $user = User::query()->whereKey($user->getKey())->firstOrFail();

            $this->adminProtection->assertUserCanBeDeleted($user, $actor);

            $user->delete();

            AuditLog::record('user_deleted', User::class, $user->id, [
                'name' => $user->name,
                'email' => $user->email,
            ]);
        });

        return redirect()->route('admin.usuarios.index')->with('success', 'El usuario se eliminó correctamente.');
    }
}
