<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarProspectoRequest;
use App\Models\Prospecto;
use App\Models\ServicioTecnologico;
use App\Models\User;
use App\Services\GestionProspectos;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use App\Support\PresentacionReclamacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Prospectos del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y permisos `prospectos.*`
 * (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por {@see GestionProspectos}. La revisión llega
 * en `_revision` ({@see PrecondicionesPanel}).
 *
 * Datos personales: toda respuesta va con `Cache-Control: no-store, private`; el id interno no sale; el listado
 * enmascara el correo y no incluye teléfono.
 */
final class ProspectoController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['creado' => 'created_at', 'nombre' => 'nombre_contacto', 'seguimiento' => 'proximo_seguimiento'];

    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly GestionProspectos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:prospectos.ver', only: ['index', 'show', 'opcionesResponsables']),
            new Middleware('permission:prospectos.crear', only: ['store', 'desdeCotizacion']),
            new Middleware('permission:prospectos.crear|prospectos.editar', only: ['opcionesServicios']),
            new Middleware('permission:prospectos.editar', only: ['update', 'estado', 'responsable']),
            new Middleware('permission:prospectos.convertir', only: ['convertir']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Prospecto::ESTADOS),
            'origen' => ListFilter::in($request, 'origen', Prospecto::ORIGENES),
            'seguimiento_pendiente' => $request->boolean('seguimiento_pendiente'),
        ];

        $consulta = Prospecto::query()
            ->select(['id', 'identificador', 'nombre_contacto', 'empresa', 'email', 'origen', 'estado', 'servicio_interes_id', 'responsable_id', 'proximo_seguimiento', 'created_at'])
            ->with(['responsable:id,name', 'servicioInteres:id,nombre']);
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['nombre_contacto', 'empresa', 'email'], $filtros['busqueda']);
        }
        foreach (['estado', 'origen'] as $filtro) {
            if ($filtros[$filtro] !== '') {
                $consulta->where($filtro, $filtros[$filtro]);
            }
        }
        if ($filtros['seguimiento_pendiente']) {
            $consulta->whereNotNull('proximo_seguimiento')
                ->whereIn('estado', ['nuevo', 'contactado', 'calificado'])
                ->whereDate('proximo_seguimiento', '<=', CarbonImmutable::now('America/Lima')->toDateString());
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-creado');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Prospecto $p): array => [
            'identificador' => $p->identificador,
            'nombre_contacto' => $p->nombre_contacto,
            'empresa' => $p->empresa,
            'email' => $p->email === null ? null : PresentacionReclamacion::correoEnmascarado($p->email),
            'origen' => $p->origen,
            'estado' => $p->estado,
            'servicio_interes' => $p->servicioInteres?->nombre,
            'responsable' => $p->responsable?->name,
            'proximo_seguimiento' => $p->proximo_seguimiento?->toDateString(),
        ], $orden, $filtros)->withHeaders(self::CABECERAS);
    }

    public function show(string $identificador): JsonResponse
    {
        return $this->detalle($this->buscar($identificador));
    }

    public function store(GuardarProspectoRequest $request): JsonResponse
    {
        $prospecto = $this->gestion->crear($request, $request->validated());

        return $this->detalle($this->buscar($prospecto->identificador), 201, 'El prospecto se registró correctamente.');
    }

    /** Convierte una solicitud de cotización en prospecto (una solicitud da un solo prospecto). */
    public function desdeCotizacion(Request $request, string $identificador): JsonResponse
    {
        $resultado = $this->gestion->desdeCotizacion($request, $identificador);

        return $this->detalle(
            $this->buscar($resultado['prospecto']->identificador),
            $resultado['repetido'] ? 200 : 201,
            $resultado['repetido'] ? 'La solicitud ya tenía un prospecto.' : 'El prospecto se creó desde la solicitud.',
        );
    }

    public function update(GuardarProspectoRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($this->buscar($identificador), 200, 'Los datos del prospecto se actualizaron.');
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'estado' => ['required', 'string', Rule::in(Prospecto::ESTADOS)],
            'motivo_descarte' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
        ]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado'], isset($datos['motivo_descarte']) ? trim($datos['motivo_descarte']) : null);

        return $this->detalle($this->buscar($identificador), 200, 'El estado del prospecto se actualizó.');
    }

    public function responsable(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['responsable_id' => ['present', 'nullable', 'integer']]);
        $this->gestion->asignarResponsable($request, $this->buscar($identificador)->identificador, $datos['responsable_id']);

        return $this->detalle($this->buscar($identificador), 200, 'El responsable del prospecto se actualizó.');
    }

    /** Convierte en cliente: crea uno con los datos del prospecto o vincula el indicado en `cliente`. */
    public function convertir(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'cliente' => ['nullable', 'uuid'],
            // Oportunidad opcional en el pipeline: exige además `pipeline.gestionar` (lo comprueba el servicio).
            'oportunidad' => ['nullable', 'array:titulo,monto_estimado,fecha_cierre_estimada'],
            'oportunidad.titulo' => ['required_with:oportunidad', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'oportunidad.monto_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'oportunidad.fecha_cierre_estimada' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->gestion->convertir($request, $this->buscar($identificador)->identificador, $datos['cliente'] ?? null, $datos['oportunidad'] ?? null);

        return $this->detalle($this->buscar($identificador), 200, 'El prospecto se convirtió en cliente.');
    }

    /** Administradores que pueden ser responsables (los mismos que acepta la regla de asignación). */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('prospectos.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones], 200, self::CABECERAS);
    }

    /** Servicios que se pueden indicar como interés (no archivados). */
    public function opcionesServicios(): JsonResponse
    {
        $opciones = ServicioTecnologico::query()->where('estado', '!=', 'archivado')->orderBy('nombre')
            ->get(['identificador_publico', 'nombre'])
            ->map(fn (ServicioTecnologico $servicio): array => ['valor' => $servicio->identificador_publico, 'texto' => $servicio->nombre])
            ->all();

        return response()->json(['data' => $opciones]);
    }

    private function buscar(string $identificador): Prospecto
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Prospecto::query()
            ->with(['responsable:id,name', 'servicioInteres:id,identificador_publico,nombre', 'cliente:id,identificador,nombre'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function detalle(Prospecto $prospecto, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $datos = [
            'identificador' => $prospecto->identificador,
            'nombre_contacto' => $prospecto->nombre_contacto,
            'empresa' => $prospecto->empresa,
            'email' => $prospecto->email,
            'telefono' => $prospecto->telefono,
            'origen' => $prospecto->origen,
            'estado' => $prospecto->estado,
            'motivo_descarte' => $prospecto->motivo_descarte,
            'servicio_interes' => $prospecto->servicioInteres === null ? null : ['identificador' => $prospecto->servicioInteres->identificador_publico, 'nombre' => $prospecto->servicioInteres->nombre],
            'responsable' => $prospecto->responsable === null ? null : ['id' => $prospecto->responsable->id, 'nombre' => $prospecto->responsable->name],
            'proximo_seguimiento' => $prospecto->proximo_seguimiento?->toDateString(),
            // Solo a quien puede consultar clientes: convertir no concede leer al cliente.
            'cliente' => $prospecto->cliente !== null && request()->user()?->can('clientes.ver') === true
                ? ['identificador' => $prospecto->cliente->identificador, 'nombre' => $prospecto->cliente->nombre]
                : null,
            'desde_cotizacion' => $prospecto->solicitud_cotizacion_id !== null,
            'creado_en' => $prospecto->created_at?->toIso8601String(),
            'revision' => $prospecto->etag(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $prospecto->etag())->header('Cache-Control', 'no-store, private');
    }
}
