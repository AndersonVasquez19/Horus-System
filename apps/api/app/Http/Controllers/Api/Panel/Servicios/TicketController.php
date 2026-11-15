<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarTicketRequest;
use App\Models\Cliente;
use App\Models\TicketSoporte;
use App\Models\User;
use App\Services\GestionSoporte;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Soporte técnico del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos `soporte.*`
 * (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por {@see GestionSoporte}; la revisión llega en
 * `_revision` ({@see PrecondicionesPanel}). Las notas solo se añaden y no usan revisión.
 *
 * El nombre del cliente solo sale a quien además puede consultar clientes. Toda respuesta con datos va con
 * `Cache-Control: no-store, private` y el id interno no sale. El listado no trae descripciones ni notas.
 */
final class TicketController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['creado' => 'created_at', 'vencimiento' => 'vence_at', 'codigo' => 'codigo', 'asunto' => 'asunto'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    /** Notas que devuelve el detalle (las más recientes). */
    private const MAX_NOTAS = 100;

    public function __construct(private readonly GestionSoporte $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:soporte.ver', only: ['index', 'show', 'opcionesAsignados']),
            new Middleware('permission:soporte.crear', only: ['store']),
            new Middleware('permission:soporte.gestionar', only: ['update', 'estado', 'asignar', 'nota']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', TicketSoporte::ESTADOS),
            'prioridad' => ListFilter::in($request, 'prioridad', TicketSoporte::PRIORIDADES),
            'abiertos' => $request->boolean('abiertos'),
            'vencidos' => $request->boolean('vencidos'),
            'cliente' => $this->uuidFiltro($request->query('cliente')),
        ];

        $consulta = TicketSoporte::query()->with(['cliente:id,identificador,nombre', 'asignado:id,name']);
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['asunto', 'codigo'], $filtros['busqueda']);
        }
        foreach (['estado', 'prioridad'] as $filtro) {
            if ($filtros[$filtro] !== '') {
                $consulta->where($filtro, $filtros[$filtro]);
            }
        }
        if ($filtros['abiertos']) {
            $consulta->whereIn('estado', ['abierto', 'en_progreso', 'en_espera_cliente']);
        }
        if ($filtros['vencidos']) {
            $consulta->whereIn('estado', ['abierto', 'en_progreso', 'en_espera_cliente'])->where('vence_at', '<', now());
        }
        if ($filtros['cliente'] !== '') {
            $consulta->whereHas('cliente', fn ($q) => $q->where('identificador', $filtros['cliente']));
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');
        $veCliente = $request->user()?->can('clientes.ver') === true;

        return Listado::responder(Listado::paginar($consulta, $request), fn (TicketSoporte $t): array => $this->resumen($t, $veCliente), $orden, $filtros)
            ->withHeaders(self::CABECERAS);
    }

    public function show(Request $request, string $identificador): JsonResponse
    {
        return $this->detalle($request, $this->buscar($identificador));
    }

    public function store(GuardarTicketRequest $request): JsonResponse
    {
        $ticket = $this->gestion->crear($request, $request->validated());

        return $this->detalle($request, $this->buscar($ticket->identificador), 201, 'El ticket se registró correctamente.');
    }

    public function update(GuardarTicketRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($request, $this->buscar($identificador), 200, 'Los datos del ticket se actualizaron.');
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(TicketSoporte::ESTADOS)]]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El estado del ticket se actualizó.');
    }

    public function asignar(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['asignado_a' => ['present', 'nullable', 'integer']]);
        $this->gestion->asignar($request, $this->buscar($identificador)->identificador, $datos['asignado_a']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'La asignación del ticket se actualizó.');
    }

    /** Añade una nota. No usa revisión: solo se agregan y no cambian el ticket. */
    public function nota(Request $request, string $identificador): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/u'],
            'interna' => ['required', 'boolean'],
        ]);
        $this->gestion->agregarNota($request, $this->buscar($identificador)->identificador, trim($datos['texto']), (bool) $datos['interna']);

        return $this->detalle($request, $this->buscar($identificador), 201, 'La nota se agregó.');
    }

    /** Administradores que pueden atender tickets. */
    public function opcionesAsignados(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('soporte.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }

    private function uuidFiltro(mixed $valor): string
    {
        return is_string($valor) && preg_match('/\A[0-9a-f-]{36}\z/i', $valor) === 1 ? strtolower($valor) : '';
    }

    private function buscar(string $identificador): TicketSoporte
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return TicketSoporte::query()
            ->with(['cliente:id,identificador,nombre', 'asignado:id,name', 'proyecto:id,identificador,codigo,nombre'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resumen(TicketSoporte $t, bool $veCliente): array
    {
        return [
            'identificador' => $t->identificador,
            'codigo' => $t->codigo,
            'asunto' => $t->asunto,
            'prioridad' => $t->prioridad,
            'estado' => $t->estado,
            'canal' => $t->canal,
            'cliente' => $veCliente && $t->cliente instanceof Cliente ? ['identificador' => $t->cliente->identificador, 'nombre' => $t->cliente->nombre] : null,
            'asignado' => $t->asignado?->name,
            'vence_en' => $t->vence_at->toIso8601String(),
            'vencido' => $t->estaVencido(),
        ];
    }

    private function detalle(Request $request, TicketSoporte $t, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $veCliente = $request->user()?->can('clientes.ver') === true;
        $datos = [
            ...$this->resumen($t, $veCliente),
            'descripcion' => $t->descripcion,
            'asignado' => $t->asignado === null ? null : ['id' => $t->asignado->id, 'nombre' => $t->asignado->name],
            'proyecto' => $t->proyecto !== null && $request->user()?->can('proyectos.ver') === true
                ? ['identificador' => $t->proyecto->identificador, 'codigo' => $t->proyecto->codigo, 'nombre' => $t->proyecto->nombre]
                : null,
            'resuelto_en' => $t->resuelto_at?->toIso8601String(),
            'cerrado_en' => $t->cerrado_at?->toIso8601String(),
            'creado_en' => $t->created_at?->toIso8601String(),
            'notas' => $t->notas()->with('autor:id,name')->limit(self::MAX_NOTAS)->get()->map(fn ($n): array => [
                'texto' => $n->texto,
                'interna' => $n->interna,
                'autor' => $n->autor?->name,
                'fecha' => $n->created_at->toIso8601String(),
            ])->all(),
            'revision' => $t->etag(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $t->etag())->header('Cache-Control', 'no-store, private');
    }
}
