<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarOportunidadRequest;
use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\User;
use App\Services\GestionOportunidades;
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
 * Pipeline de oportunidades del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos
 * `pipeline.*` (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por {@see GestionOportunidades}; la
 * revisión llega en `_revision` ({@see PrecondicionesPanel}).
 *
 * El nombre del cliente solo sale a quien además puede consultar clientes. Toda respuesta con datos va con
 * `Cache-Control: no-store, private` y el id interno no sale.
 */
final class OportunidadController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['creado' => 'created_at', 'titulo' => 'titulo', 'monto' => 'monto_estimado', 'cierre' => 'fecha_cierre_estimada'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    /** Tarjetas por columna del tablero: el resto se consulta en el listado. */
    private const TARJETAS_POR_COLUMNA = 25;

    public function __construct(private readonly GestionOportunidades $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:pipeline.ver', only: ['index', 'tablero', 'show', 'opcionesResponsables']),
            new Middleware('permission:pipeline.gestionar', only: ['store', 'update', 'etapa', 'responsable']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'etapa' => ListFilter::in($request, 'etapa', Oportunidad::ETAPAS),
            'abiertas' => $request->boolean('abiertas'),
            'cliente' => $this->uuidFiltro($request->query('cliente')),
        ];

        $consulta = Oportunidad::query()->with(['cliente:id,identificador,nombre', 'responsable:id,name']);
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['titulo'], $filtros['busqueda']);
        }
        if ($filtros['etapa'] !== '') {
            $consulta->where('etapa', $filtros['etapa']);
        }
        if ($filtros['abiertas']) {
            $consulta->whereIn('etapa', Oportunidad::ABIERTAS);
        }
        if ($filtros['cliente'] !== '') {
            $consulta->whereHas('cliente', fn ($q) => $q->where('identificador', $filtros['cliente']));
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');
        $veCliente = $request->user()?->can('clientes.ver') === true;

        return Listado::responder(Listado::paginar($consulta, $request), fn (Oportunidad $o): array => $this->resumen($o, $veCliente), $orden, $filtros)
            ->withHeaders(self::CABECERAS);
    }

    /**
     * Tablero Kanban: una columna por etapa con su total, el monto y las tarjetas más recientes. Las columnas cerradas
     * (ganada, perdida) también salen para que se pueda soltar una tarjeta en ellas.
     */
    public function tablero(Request $request): JsonResponse
    {
        $veCliente = $request->user()?->can('clientes.ver') === true;
        $columnas = [];
        foreach (Oportunidad::ETAPAS as $etapa) {
            $base = Oportunidad::query()->where('etapa', $etapa);
            $tarjetas = (clone $base)->with(['cliente:id,identificador,nombre', 'responsable:id,name'])
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::TARJETAS_POR_COLUMNA)->get();
            $columnas[] = [
                'etapa' => $etapa,
                'total' => (clone $base)->count(),
                'monto' => number_format((float) (clone $base)->sum('monto_estimado'), 2, '.', ''),
                'tarjetas' => $tarjetas->map(fn (Oportunidad $o): array => $this->resumen($o, $veCliente))->all(),
            ];
        }

        return response()->json(['data' => $columnas, 'meta' => ['tarjetas_por_columna' => self::TARJETAS_POR_COLUMNA]], 200, self::CABECERAS);
    }

    public function show(Request $request, string $identificador): JsonResponse
    {
        return $this->detalle($request, $this->buscar($identificador));
    }

    public function store(GuardarOportunidadRequest $request): JsonResponse
    {
        $oportunidad = $this->gestion->crear($request, $request->validated());

        return $this->detalle($request, $this->buscar($oportunidad->identificador), 201, 'La oportunidad se registró correctamente.');
    }

    public function update(GuardarOportunidadRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($request, $this->buscar($identificador), 200, 'Los datos de la oportunidad se actualizaron.');
    }

    public function etapa(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'etapa' => ['required', 'string', Rule::in(Oportunidad::ETAPAS)],
            'motivo_perdida' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
        ]);
        $this->gestion->cambiarEtapa($request, $this->buscar($identificador)->identificador, $datos['etapa'], isset($datos['motivo_perdida']) ? trim($datos['motivo_perdida']) : null);

        return $this->detalle($request, $this->buscar($identificador), 200, 'La etapa de la oportunidad se actualizó.');
    }

    public function responsable(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['responsable_id' => ['present', 'nullable', 'integer']]);
        $this->gestion->asignarResponsable($request, $this->buscar($identificador)->identificador, $datos['responsable_id']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El responsable de la oportunidad se actualizó.');
    }

    /** Administradores que pueden ser responsables (los mismos que acepta la regla de asignación). */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('pipeline.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }

    private function uuidFiltro(mixed $valor): string
    {
        return is_string($valor) && preg_match('/\A[0-9a-f-]{36}\z/i', $valor) === 1 ? strtolower($valor) : '';
    }

    private function buscar(string $identificador): Oportunidad
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Oportunidad::query()
            ->with(['responsable:id,name', 'cliente:id,identificador,nombre', 'servicio:id,identificador_publico,nombre', 'cotizacion:id,identificador'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resumen(Oportunidad $o, bool $veCliente): array
    {
        return [
            'identificador' => $o->identificador,
            'titulo' => $o->titulo,
            'etapa' => $o->etapa,
            'monto_estimado' => $o->monto_estimado,
            'moneda' => $o->moneda,
            'probabilidad' => $o->probabilidad,
            'fecha_cierre_estimada' => $o->fecha_cierre_estimada?->toDateString(),
            'cliente' => $veCliente && $o->cliente instanceof Cliente ? ['identificador' => $o->cliente->identificador, 'nombre' => $o->cliente->nombre] : null,
            'responsable' => $o->responsable?->name,
            'revision' => $o->etag(),
        ];
    }

    private function detalle(Request $request, Oportunidad $o, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $veCliente = $request->user()?->can('clientes.ver') === true;
        $datos = [
            ...$this->resumen($o, $veCliente),
            'motivo_perdida' => $o->motivo_perdida,
            'servicio' => $o->servicio === null ? null : ['identificador' => $o->servicio->identificador_publico, 'nombre' => $o->servicio->nombre],
            'cotizacion' => $o->cotizacion !== null && $request->user()?->can('cotizaciones.ver') === true ? $o->cotizacion->identificador : null,
            'desde_prospecto' => $o->prospecto_id !== null,
            'responsable' => $o->responsable === null ? null : ['id' => $o->responsable->id, 'nombre' => $o->responsable->name],
            'cerrada_en' => $o->cerrada_at?->toIso8601String(),
            'creado_en' => $o->created_at?->toIso8601String(),
            'historial' => $o->historial()->with('autor:id,name')->limit(100)->get()->map(fn ($fila): array => [
                'anterior' => $fila->etapa_anterior,
                'etapa' => $fila->etapa_nueva,
                'autor' => $fila->autor?->name,
                'fecha' => $fila->created_at->toIso8601String(),
            ])->all(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $o->etag())->header('Cache-Control', 'no-store, private');
    }
}
