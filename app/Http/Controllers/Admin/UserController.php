<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminProtection;
use App\Support\Email;
use App\Support\ListFilter;
use App\Support\PasswordPolicy;
use App\Support\SessionRevoker;
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

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            $query->where(function ($query) use ($busqueda): void {
                $query->whereLike('name', "%{$busqueda}%")
                    ->orWhereLike('email', "%{$busqueda}%");
            });
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
        Email::normalizeRequest($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Email::uniqueRule()],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ] + PasswordPolicy::messages());

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
        Email::normalizeRequest($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Email::uniqueRule($user->id)],
            'password' => ['nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ] + PasswordPolicy::messages());

        // Asignar roles concede permisos: exige su propio permiso además de usuarios.editar.
        $actor = $request->user();
        $canAssignRoles = $actor->can('usuarios.asignar-roles');
        abort_if($request->has('roles') && ! $canAssignRoles, 403);

        /** @var list<string> $newRoles */
        $newRoles = array_values(array_unique($data['roles'] ?? []));

        // Cambiar roles puede dejar al sistema sin administradores: se serializa y se valida bajo bloqueo.
        $this->adminProtection->runExclusively(function () use ($request, $user, $data, $actor, $canAssignRoles, $newRoles): void {
            $user = User::query()->whereKey($user->getKey())->firstOrFail();
            $passwordChanged = false;

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
                // Revoca los JWT emitidos con la contraseña anterior; las sesiones web se cierran tras guardar.
                $user->jwt_version = (int) $user->jwt_version + 1;
                $passwordChanged = true;
            }

            $user->save();

            if ($passwordChanged) {
                // Quien cambia su propia clave conserva la sesión actual; las demás se cierran.
                SessionRevoker::revokeSessions($user, $user->is($actor) ? $request->session()->getId() : null);
            }

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
