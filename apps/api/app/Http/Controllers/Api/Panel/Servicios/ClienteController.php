<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarClienteRequest;
use App\Models\Cliente;
use App\Models\ClienteContacto;
use App\Models\User;
use App\Services\GestionClientes;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use App\Support\PresentacionReclamacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Clientes del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos `clientes.*`
 * (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por {@see GestionClientes} (bloqueo, revisión
 * `If-Match`, documento único y auditoría). La revisión llega en `_revision` ({@see PrecondicionesPanel}).
 *
 * Datos personales: toda respuesta va con `Cache-Control: no-store, private`; el id interno no sale; el listado
 * enmascara correo y documento y solo el detalle los entrega completos.
 */
final class ClienteController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['nombre' => 'nombre', 'creado' => 'created_at'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionClientes $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:clientes.ver', only: ['index', 'show', 'opcionesResponsables']),
            new Middleware('permission:clientes.crear', only: ['store']),
            new Middleware('permission:clientes.editar', only: ['update', 'estado', 'responsable']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Cliente::ESTADOS),
            'tipo' => ListFilter::in($request, 'tipo', Cliente::TIPOS),
        ];

        $consulta = Cliente::query()
            ->select(['id', 'identificador', 'tipo', 'nombre', 'documento_tipo', 'documento_numero', 'email', 'estado', 'responsable_id', 'created_at'])
            ->with('responsable:id,name');
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['nombre', 'email', 'documento_numero'], $filtros['busqueda']);
        }
        foreach (['estado', 'tipo'] as $filtro) {
            if ($filtros[$filtro] !== '') {
                $consulta->where($filtro, $filtros[$filtro]);
            }
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'nombre');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Cliente $cliente): array => [
            'identificador' => $cliente->identificador,
            'tipo' => $cliente->tipo,
            'nombre' => $cliente->nombre,
            'documento_tipo' => $cliente->documento_tipo,
            'documento' => self::documentoEnmascarado($cliente->documento_numero),
            'email' => $cliente->email === null ? null : PresentacionReclamacion::correoEnmascarado($cliente->email),
            'estado' => $cliente->estado,
            'responsable' => $cliente->responsable?->name,
        ], $orden, $filtros)->withHeaders(self::CABECERAS);
    }

    public function show(string $identificador): JsonResponse
    {
        return $this->detalle($this->buscar($identificador));
    }

    public function store(GuardarClienteRequest $request): JsonResponse
    {
        $cliente = $this->gestion->crear($request, $request->validated());

        return $this->detalle($this->buscar($cliente->identificador), 201, 'El cliente se registró correctamente.');
    }

    public function update(GuardarClienteRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($this->buscar($identificador), 200, 'Los datos del cliente se actualizaron.');
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Cliente::ESTADOS)]]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado']);

        return $this->detalle($this->buscar($identificador), 200, 'El estado del cliente se actualizó.');
    }

    public function responsable(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['responsable_id' => ['present', 'nullable', 'integer']]);
        $this->gestion->asignarResponsable($request, $this->buscar($identificador)->identificador, $datos['responsable_id']);

        return $this->detalle($this->buscar($identificador), 200, 'El responsable del cliente se actualizó.');
    }

    /** Administradores que pueden ser responsables (los mismos que acepta la regla de asignación). */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('clientes.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }

    private function buscar(string $identificador): Cliente
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Cliente::query()->with(['responsable:id,name', 'contactos'])->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function detalle(Cliente $cliente, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $datos = [
            'identificador' => $cliente->identificador,
            'tipo' => $cliente->tipo,
            'nombre' => $cliente->nombre,
            'documento_tipo' => $cliente->documento_tipo,
            'documento_numero' => $cliente->documento_numero,
            'email' => $cliente->email,
            'telefono' => $cliente->telefono,
            'direccion' => $cliente->direccion,
            'estado' => $cliente->estado,
            'responsable' => $cliente->responsable === null ? null : ['id' => $cliente->responsable->id, 'nombre' => $cliente->responsable->name],
            'contactos' => $cliente->contactos->map(fn (ClienteContacto $c): array => [
                'nombre' => $c->nombre, 'cargo' => $c->cargo, 'email' => $c->email, 'telefono' => $c->telefono, 'principal' => $c->principal,
            ])->values()->all(),
            'creado_en' => $cliente->created_at?->toIso8601String(),
            'revision' => $cliente->etag(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $cliente->etag())->header('Cache-Control', 'no-store, private');
    }

    /** Solo los últimos 3 caracteres: el listado no necesita el documento completo. */
    private static function documentoEnmascarado(?string $numero): ?string
    {
        if ($numero === null || $numero === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($numero) - 3)).substr($numero, -3);
    }
}
