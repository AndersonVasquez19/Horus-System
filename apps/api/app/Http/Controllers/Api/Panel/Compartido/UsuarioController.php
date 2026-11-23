<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\GestionUsuarios;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\ListFilter;
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\Listado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Usuarios del sistema para los dos paneles. Toda escritura pasa por `GestionUsuarios`, la misma implementación del
 * Blade: actor releído con datos vigentes, escalada de privilegios (R1 a R3), último administrador, revocación de
 * sesiones y JWT al cambiar la contraseña, y auditoría sin contraseñas. Las denegaciones son un 403 genérico.
 *
 * Nunca se expone la contraseña, su hash, tokens ni `remember_token`: la representación es una lista cerrada.
 */
final class UsuarioController extends Controller implements HasMiddleware
{
    private const LIMITE_EXPORTACION = 5000;

    private const ORDENABLES = ['nombre' => 'name', 'email' => 'email', 'creado' => 'created_at'];

    public function __construct(private readonly GestionUsuarios $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:usuarios.ver', only: ['index', 'show', 'exportar']),
            new Middleware('permission:usuarios.crear', only: ['store']),
            new Middleware('permission:usuarios.editar', only: ['update', 'suspender', 'desbloquear', 'cerrarSesiones', 'invitar']),
            new Middleware('permission:usuarios.eliminar', only: ['destroy', 'desactivar', 'restaurar']),
            // Los formularios necesitan los nombres de rol aunque quien gestiona no tenga `roles.ver`.
            new Middleware('permission:usuarios.crear|usuarios.editar', only: ['rolesAsignables']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$consulta, $filtros, $orden] = $this->consultaFiltrada($request);

        return Listado::responder(
            Listado::paginar($consulta, $request),
            fn (User $usuario): array => self::representar($usuario),
            $orden,
            $filtros,
        );
    }

    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        [$consulta, $filtros] = $this->consultaFiltrada($request);
        $filas = $consulta->limit(self::LIMITE_EXPORTACION)->get()->map(fn (User $u): array => [
            $u->id,
            $u->name,
            $u->email,
            $u->roles->pluck('name')->implode(', '),
            $u->email_verified_at !== null ? 'Sí' : 'No',
            $u->created_at?->format('Y-m-d'),
        ]);

        return ExportacionListado::responder(
            'usuarios',
            'Usuarios',
            ExportacionListado::formato($request),
            ['ID', 'Nombre', 'Correo', 'Roles', 'Verificado', 'Registrado'],
            $filas,
            'usuarios_exportados',
            User::class,
            AuditoriaAcademica::actorDe($request),
            ['rol' => $filtros['rol'] ?: null, 'estado' => $filtros['estado'] ?: null, 'con_busqueda' => $filtros['busqueda'] !== ''],
        );
    }

    /** @return array{0: Builder<User>, 1: array{busqueda: string, rol: string, estado: string, eliminados?: string}, 2: string} */
    private function consultaFiltrada(Request $request): array
    {
        $eliminados = $request->boolean('eliminados');
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'rol' => ListFilter::text($request, 'rol', 100),
            'estado' => ListFilter::in($request, 'estado', ['verificado', 'no_verificado', 'suspendido']),
            ...($eliminados ? ['eliminados' => '1'] : []),
        ];
        $consulta = User::query()->with('roles:id,name');
        if ($eliminados) {
            $consulta->whereNotNull('deleted_at');
        } else {
            $consulta->whereNull('deleted_at');
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['name', 'email'], $filtros['busqueda']);
        }
        if ($filtros['rol'] === 'sin_rol') {
            $consulta->whereDoesntHave('roles');
        } elseif ($filtros['rol'] !== '') {
            $rol = $filtros['rol'];
            $consulta->whereHas('roles', fn (Builder $q) => $q->where('name', $rol));
        }
        if ($filtros['estado'] === 'verificado') {
            $consulta->whereNotNull('email_verified_at');
        } elseif ($filtros['estado'] === 'no_verificado') {
            $consulta->whereNull('email_verified_at');
        } elseif ($filtros['estado'] === 'suspendido') {
            $consulta->whereNotNull('bloqueado_hasta')->where('bloqueado_hasta', '>', now());
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'nombre');

        return [$consulta, $filtros, $orden];
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
        $datos = [
            'name' => Entrada::texto($data['name']),
            'email' => Entrada::texto($data['email']),
            'password' => Entrada::texto($data['password']),
        ];
        if (array_key_exists('temporal', $data)) {
            $datos['temporal'] = $data['temporal'] === null ? null : Entrada::booleano($data['temporal']);
        }
        $usuario = $this->gestion->crear($this->usuarioAutenticado($request), $datos, Entrada::listaDeTexto($data['roles'] ?? []));

        return response()->json([
            'message' => 'El usuario se registró correctamente.',
            'data' => self::representar($usuario->load('roles:id,name')),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $datos = [
            'name' => Entrada::texto($data['name']),
            'email' => Entrada::texto($data['email']),
        ];
        if (array_key_exists('password', $data)) {
            $datos['password'] = $data['password'] === null ? null : Entrada::texto($data['password']);
        }
        if (array_key_exists('temporal', $data)) {
            $datos['temporal'] = $data['temporal'] === null ? null : Entrada::booleano($data['temporal']);
        }
        $this->gestion->actualizar($this->usuarioAutenticado($request), $user, $datos, Entrada::listaDeTexto($data['roles'] ?? []), $request->has('roles'), $request->session()->getId());

        return response()->json([
            'message' => 'Los datos del usuario se actualizaron correctamente.',
            'data' => self::representar($user->refresh()->load('roles:id,name')),
        ]);
    }

    public function desactivar(Request $request, User $user): JsonResponse
    {
        $this->gestion->desactivar($this->usuarioAutenticado($request), $user);

        return response()->json([
            'message' => 'El usuario se dio de baja lógica correctamente.',
            'data' => self::representar($user->refresh()->load('roles:id,name')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->gestion->eliminar($this->usuarioAutenticado($request), $user);

        return response()->json(['message' => 'El usuario se eliminó permanentemente.']);
    }

    public function restaurar(Request $request, int $user): JsonResponse
    {
        $restaurado = $this->gestion->restaurar($this->usuarioAutenticado($request), $user);

        return response()->json([
            'message' => 'El usuario se restauró correctamente.',
            'data' => self::representar($restaurado->load('roles:id,name')),
        ]);
    }

    public function suspender(Request $request, User $user): JsonResponse
    {
        $datos = $request->validate([
            'dias' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'motivo' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
        ]);
        $dias = (int) ($datos['dias'] ?? 30);
        $hasta = Carbon::now()->addDays($dias);

        $this->gestion->suspender($this->usuarioAutenticado($request), $user, $hasta, isset($datos['motivo']) ? Entrada::texto($datos['motivo']) : null);

        return response()->json([
            'message' => "Cuenta suspendida por {$dias} día(s).",
            'data' => self::representar($user->refresh()->load('roles:id,name')),
        ]);
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        $this->gestion->desbloquear($this->usuarioAutenticado($request), $user);

        return response()->json([
            'message' => 'La suspensión de la cuenta se levantó correctamente.',
            'data' => self::representar($user->refresh()->load('roles:id,name')),
        ]);
    }

    public function cerrarSesiones(Request $request, User $user): JsonResponse
    {
        $this->gestion->cerrarSesiones($this->usuarioAutenticado($request), $user, $request->session()->getId());

        return response()->json([
            'message' => 'Se cerraron todas las sesiones activas de la cuenta.',
        ]);
    }

    public function invitar(Request $request, User $user): JsonResponse
    {
        $this->gestion->invitar($this->usuarioAutenticado($request), $user);

        return response()->json([
            'message' => 'Se generó y envió el enlace de acceso/restablecimiento al correo registrado.',
        ]);
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
            'suspendido' => $usuario->estaSuspendido(),
            'bloqueado_hasta' => $usuario->bloqueado_hasta?->toIso8601String(),
            'motivo_bloqueo' => $usuario->motivo_bloqueo,
            'debe_cambiar_clave' => (bool) $usuario->debe_cambiar_password,
            'two_factor_habilitado' => $usuario->tiene2faActivo(),
            'eliminado' => $usuario->estaDadoDeBaja(),
            'creado_en' => $usuario->created_at?->toIso8601String(),
        ];
    }
}
