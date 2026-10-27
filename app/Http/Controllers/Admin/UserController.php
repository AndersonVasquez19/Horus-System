<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'permission:usuarios.ver'])->only(['index', 'show']);
        $this->middleware(['auth', 'permission:usuarios.crear'])->only(['create', 'store']);
        $this->middleware(['auth', 'permission:usuarios.editar'])->only(['edit', 'update']);
        $this->middleware(['auth', 'permission:usuarios.eliminar'])->only(['destroy']);
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
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

        $previous = $user->only(['name', 'email', 'roles']);
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        $roles = $data['roles'] ?? [];
        $user->syncRoles($roles);

        AuditLog::record('user_updated', User::class, $user->id, [
            'before' => $previous,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->all(),
            ],
        ], auth()->id());

        return redirect()->route('admin.usuarios.index')->with('success', 'Los datos del usuario se actualizaron correctamente.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['usuario' => 'No puedes eliminar tu propio usuario.']);
        }

        $user->delete();

        AuditLog::record('user_deleted', User::class, $user->id, [
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return redirect()->route('admin.usuarios.index')->with('success', 'El usuario se eliminó correctamente.');
    }
}
