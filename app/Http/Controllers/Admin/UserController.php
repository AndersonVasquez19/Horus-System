<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminProtection;
use App\Services\VerificacionRegistroService;
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

        $actor = $request->user();

        /** @var list<string> $roles */
        $roles = array_values(array_unique($data['roles'] ?? []));

        // Sprint 21.1: la creación también es exclusiva. Los permisos del actor se releen bajo el bloqueo y
        // asignar roles exige su propio permiso, sin superar los privilegios del actor (R2).
        $this->adminProtection->runAdministrative(function () use ($data, $actor, $roles): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.crear'], 'usuario.crear', User::class, null);
            $this->adminProtection->assertMayRequestRoles($alcance, $roles !== [], 'usuario.crear', null);
            $this->adminProtection->assertCanAssignRoles($alcance, null, [], $roles, 'usuario.crear');

            $usuario = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            // Cuenta creada por un administrador: el correo queda dado por válido para que pueda entrar aunque
            // la verificación de registro esté activa (no es asignable en masa).
            $usuario->forceFill(['email_verified_at' => now()])->save();

            if ($roles !== []) {
                $usuario->syncRoles($roles);
                $this->adminProtection->forgetPermissionCacheAfterCommit();
            }

            AuditLog::record('user_created', User::class, $usuario->id, [
                'name' => $usuario->name,
                'email' => $usuario->email,
                'roles' => $usuario->roles->pluck('name')->all(),
            ], $alcance['actor']->id);
        });

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

        $actor = $request->user();
        $solicitaRoles = $request->has('roles');

        /** @var list<string> $newRoles */
        $newRoles = array_values(array_unique($data['roles'] ?? []));

        // Sprint 21.1: toda decisión de autorización se toma bajo el bloqueo y con datos vigentes (no con la
        // caché de Spatie). Orden: actor vigente -> objetivo -> R1/R1+ -> R2 -> reglas 422 de último admin.
        $this->adminProtection->runAdministrative(function () use ($request, $user, $data, $actor, $solicitaRoles, $newRoles): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.actualizar', User::class, $user->getKey());
            $actor = $alcance['actor'];
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $passwordChanged = false;

            $this->adminProtection->assertCanModifyUser($alcance, $user, 'usuario.actualizar');
            $this->adminProtection->assertMayRequestRoles($alcance, $solicitaRoles, 'usuario.actualizar', $user->id);

            $puedeAsignar = in_array('usuarios.asignar-roles', $alcance['permisos'], true);
            // Un no administrador que edita su propio perfil sin enviar `roles` no cambia sus roles (D-11):
            // la ausencia del campo no se interpreta como «quitar todos».
            $cambiaRoles = $puedeAsignar && ($solicitaRoles || ! $actor->is($user) || $alcance['admin']);
            $rolesActuales = $this->adminProtection->roleNamesOf($user);

            if ($cambiaRoles) {
                $this->adminProtection->assertCanAssignRoles($alcance, $user, $rolesActuales, $newRoles, 'usuario.actualizar');
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

                // Con la verificación activa la sesión se compara con `jwt_version`: sin actualizarla, quien cambia
                // su propia clave quedaría expulsado en la siguiente petición pese a conservar la sesión.
                if ($user->is($actor) && VerificacionRegistroService::enabled()) {
                    $request->session()->put('auth.verification_version', (int) $user->jwt_version);
                }
            }

            if ($cambiaRoles) {
                $user->syncRoles($newRoles);
                $this->adminProtection->forgetPermissionCacheAfterCommit();
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

        $this->adminProtection->runAdministrative(function () use ($user, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.eliminar'], 'usuario.eliminar', User::class, $user->getKey());
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // R3/R1+ primero (403); después las reglas de integridad (422): último administrador y uno mismo.
            $this->adminProtection->assertCanDeleteUser($alcance, $user, 'usuario.eliminar');
            $this->adminProtection->assertUserCanBeDeleted($user, $alcance['actor']);

            $user->delete();

            AuditLog::record('user_deleted', User::class, $user->id, [
                'name' => $user->name,
                'email' => $user->email,
            ], $alcance['actor']->id);
        });

        return redirect()->route('admin.usuarios.index')->with('success', 'El usuario se eliminó correctamente.');
    }
}
