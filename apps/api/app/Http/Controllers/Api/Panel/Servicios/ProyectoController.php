<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarProyectoRequest;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\GestionProyectos;
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
 * Proyectos del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos `proyectos.*`
 * (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por {@see GestionProyectos}; la revisión llega en
 * `_revision` ({@see PrecondicionesPanel}) y los hitos avanzan la del proyecto.
 *
 * El nombre del cliente solo sale a quien además puede consultar clientes. Toda respuesta con datos va con
 * `Cache-Control: no-store, private` y el id interno no sale.
 */
final class ProyectoController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['creado' => 'created_at', 'nombre' => 'nombre', 'codigo' => 'codigo', 'fin' => 'fecha_fin_estimada'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionProyectos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:proyectos.ver', only: ['index', 'show', 'opcionesResponsables']),
            new Middleware('permission:proyectos.crear', only: ['store']),
            new Middleware('permission:proyectos.editar', only: ['update', 'estado', 'responsable', 'hitoStore', 'hitoUpdate', 'hitoCompletar', 'hitoEliminar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Proyecto::ESTADOS),
            'activos' => $request->boolean('activos'),
            'cliente' => $this->uuidFiltro($request->query('cliente')),
        ];

        $consulta = Proyecto::query()->with(['cliente:id,identificador,nombre', 'responsable:id,name'])
            ->withCount(['hitos', 'hitos as hitos_completados_count' => fn ($q) => $q->whereNotNull('completado_at')]);
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['nombre', 'codigo'], $filtros['busqueda']);
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['activos']) {
            $consulta->whereIn('estado', Proyecto::ACTIVOS);
        }
        if ($filtros['cliente'] !== '') {
            $consulta->whereHas('cliente', fn ($q) => $q->where('identificador', $filtros['cliente']));
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');
        $veCliente = $request->user()?->can('clientes.ver') === true;

        return Listado::responder(Listado::paginar($consulta, $request), fn (Proyecto $p): array => $this->resumen($p, $veCliente), $orden, $filtros)
            ->withHeaders(self::CABECERAS);
    }

    public function show(Request $request, string $identificador): JsonResponse
    {
        return $this->detalle($request, $this->buscar($identificador));
    }

    public function store(GuardarProyectoRequest $request): JsonResponse
    {
        $resultado = $this->gestion->crear($request, $request->validated());

        return $this->detalle(
            $request,
            $this->buscar($resultado['proyecto']->identificador),
            $resultado['repetido'] ? 200 : 201,
            $resultado['repetido'] ? 'La oportunidad ya tenía un proyecto.' : 'El proyecto se registró correctamente.',
        );
    }

    public function update(GuardarProyectoRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($request, $this->buscar($identificador), 200, 'Los datos del proyecto se actualizaron.');
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Proyecto::ESTADOS)]]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El estado del proyecto se actualizó.');
    }

    public function responsable(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['responsable_id' => ['present', 'nullable', 'integer']]);
        $this->gestion->asignarResponsable($request, $this->buscar($identificador)->identificador, $datos['responsable_id']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El responsable del proyecto se actualizó.');
    }

    public function hitoStore(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->agregarHito($request, $this->buscar($identificador)->identificador, $this->datosHito($request));

        return $this->detalle($request, $this->buscar($identificador), 201, 'El hito se agregó.');
    }

    public function hitoUpdate(Request $request, string $identificador, string $hito): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizarHito($request, $this->buscar($identificador)->identificador, $hito, $this->datosHito($request));

        return $this->detalle($request, $this->buscar($identificador), 200, 'El hito se actualizó.');
    }

    public function hitoCompletar(Request $request, string $identificador, string $hito): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['completado' => ['required', 'boolean']]);
        $this->gestion->completarHito($request, $this->buscar($identificador)->identificador, $hito, (bool) $datos['completado']);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El hito se actualizó.');
    }

    public function hitoEliminar(Request $request, string $identificador, string $hito): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->eliminarHito($request, $this->buscar($identificador)->identificador, $hito);

        return $this->detalle($request, $this->buscar($identificador), 200, 'El hito se eliminó.');
    }

    /** Administradores que pueden ser responsables (los mismos que acepta la regla de asignación). */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('proyectos.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }

    /** @return array<string, mixed> */
    private function datosHito(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'fecha_objetivo' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function uuidFiltro(mixed $valor): string
    {
        return is_string($valor) && preg_match('/\A[0-9a-f-]{36}\z/i', $valor) === 1 ? strtolower($valor) : '';
    }

    private function buscar(string $identificador): Proyecto
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Proyecto::query()
            ->with(['responsable:id,name', 'cliente:id,identificador,nombre', 'oportunidad:id,identificador,titulo', 'hitos'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resumen(Proyecto $p, bool $veCliente): array
    {
        $hitos = (int) ($p->hitos_count ?? $p->hitos->count());
        $completados = (int) ($p->hitos_completados_count ?? $p->hitos->whereNotNull('completado_at')->count());

        return [
            'identificador' => $p->identificador,
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'estado' => $p->estado,
            'cliente' => $veCliente && $p->cliente instanceof Cliente ? ['identificador' => $p->cliente->identificador, 'nombre' => $p->cliente->nombre] : null,
            'responsable' => $p->responsable?->name,
            'fecha_inicio' => $p->fecha_inicio?->toDateString(),
            'fecha_fin_estimada' => $p->fecha_fin_estimada?->toDateString(),
            // El avance se calcula (hitos completados / hitos), no se guarda.
            'hitos' => ['total' => $hitos, 'completados' => $completados, 'avance' => $hitos === 0 ? null : (int) round($completados * 100 / $hitos)],
        ];
    }

    private function detalle(Request $request, Proyecto $p, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $veCliente = $request->user()?->can('clientes.ver') === true;
        $datos = [
            ...$this->resumen($p, $veCliente),
            'descripcion' => $p->descripcion,
            'presupuesto' => $p->presupuesto,
            'moneda' => $p->moneda,
            'fecha_fin_real' => $p->fecha_fin_real?->toDateString(),
            'responsable' => $p->responsable === null ? null : ['id' => $p->responsable->id, 'nombre' => $p->responsable->name],
            'oportunidad' => $p->oportunidad !== null && $request->user()?->can('pipeline.ver') === true
                ? ['identificador' => $p->oportunidad->identificador, 'titulo' => $p->oportunidad->titulo]
                : null,
            'desde_oportunidad' => $p->oportunidad_id !== null,
            'creado_en' => $p->created_at?->toIso8601String(),
            'hitos_detalle' => $p->hitos->map(fn ($h): array => [
                'identificador' => $h->identificador,
                'nombre' => $h->nombre,
                'orden' => $h->orden,
                'fecha_objetivo' => $h->fecha_objetivo?->toDateString(),
                'completado_en' => $h->completado_at?->toIso8601String(),
            ])->all(),
            'revision' => $p->etag(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $p->etag())->header('Cache-Control', 'no-store, private');
    }
}
