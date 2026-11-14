<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\GestionUsuarios;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Models\Role;

/**
 * Usuarios del sistema para los dos paneles. Toda escritura pasa por `GestionUsuarios`, la misma implementación del
 * Blade: actor releído con datos vigentes, escalada de privilegios (R1 a R3), último administrador, revocación de
 * sesiones y JWT al cambiar la contraseña, y auditoría sin contraseñas. Las denegaciones son un 403 genérico.
 *
 * Nunca se expone la contraseña, su hash, tokens ni `remember_token`: la representación es una lista cerrada.
 */
final class UsuarioController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['nombre' => 'name', 'email' => 'email', 'creado' => 'created_at'];

    public function __construct(private readonly GestionUsuarios $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:usuarios.ver', only: ['index', 'show']),
            new Middleware('permission:usuarios.crear', only: ['store']),
            new Middleware('permission:usuarios.editar', only: ['update']),
            new Middleware('permission:usuarios.eliminar', only: ['destroy']),
            // Los formularios necesitan los nombres de rol aunque quien gestiona no tenga `roles.ver`.
            new Middleware('permission:usuarios.crear|usuarios.editar', only: ['rolesAsignables']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $busqueda = ListFilter::text($request, 'busqueda');
        $consulta = User::query()->with('roles:id,name');
        if ($busqueda !== '') {
            BusquedaLike::contiene($consulta, ['name', 'email'], $busqueda);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'nombre');

        return Listado::responder(
            Listado::paginar($consulta, $request),
            fn (User $usuario): array => self::representar($usuario),
            $orden,
            ['busqueda' => $busqueda],
        );
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => self::representar($user->load('roles:id,name'))]);
    }

    public function rolesAsignables(): JsonResponse
    {
        return response()->json(['data' => Role::query()->orderBy('name')->pluck('name')->all()]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $usuario = $this->gestion->crear($request->user(), $data, $data['roles'] ?? []);

        return response()->json([
            'message' => 'El usuario se registró correctamente.',
            'data' => self::representar($usuario->load('roles:id,name')),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $this->gestion->actualizar($request->user(), $user, $data, $data['roles'] ?? [], $request->has('roles'), $request->session()->getId());

        return response()->json([
            'message' => 'Los datos del usuario se actualizaron correctamente.',
            'data' => self::representar($user->refresh()->load('roles:id,name')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->gestion->eliminar($request->user(), $user);

        return response()->json(['message' => 'El usuario se eliminó correctamente.']);
    }

    /** @return array<string, mixed> */
    private static function representar(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
            'roles' => $usuario->roles->pluck('name')->sort()->values()->all(),
            'verificado' => $usuario->email_verified_at !== null,
            'creado_en' => $usuario->created_at?->toIso8601String(),
        ];
    }
}
