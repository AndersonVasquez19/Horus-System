<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarCotizacionRequest;
use App\Http\Requests\GuardarCotizacionRequest;
use App\Models\CotizacionHistorial;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Services\GestionCotizaciones;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Bandeja de cotizaciones del panel de servicios. Conserva la restricción comercial vigente: rol `admin` y
 * permisos `cotizaciones.*` (docs/restriccion-acceso-comercial-admin.md). Toda escritura pasa por
 * {@see GestionCotizaciones} (bloqueo, revisión `If-Match`, idempotencia, historial y auditoría) con los mismos
 * Form Requests que el módulo Blade. La revisión llega en `_revision` y la clave de idempotencia en `_clave`
 * ({@see PrecondicionesPanel}). El listado no incluye datos de contacto ni la necesidad del cliente.
 */
final class CotizacionController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['recibida', 'en_revision'];

    public const ORIGENES = ['publico', 'administrativo'];

    private const ORDENABLES = ['recibida' => 'recibida_at', 'codigo' => 'codigo', 'seguimiento' => 'proximo_seguimiento'];

    public function __construct(private readonly GestionCotizaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin'),
            new Middleware('permission:cotizaciones.ver', only: ['index', 'show']),
            new Middleware('permission:cotizaciones.crear', only: ['store', 'opcionesServicios']),
            new Middleware('permission:cotizaciones.editar', only: ['update']),
            new Middleware('permission:cotizaciones.gestionar', only: ['estado', 'responsable', 'seguimiento']),
            new Middleware('permission:cotizaciones.ver', only: ['opcionesResponsables']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = SolicitudCotizacion::query()
            ->select(['id', 'identificador', 'codigo', 'recibida_at', 'estado', 'responsable_id', 'servicio_uuid', 'servicio_nombre_historico', 'proximo_seguimiento', 'origen'])
            ->with('responsable:id,name');

        $servicio = ListFilter::text($request, 'servicio', 36);
        $filtros = [
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'origen' => ListFilter::in($request, 'origen', self::ORIGENES),
            'servicio' => preg_match('/\A[0-9a-f-]{36}\z/i', $servicio) === 1 ? strtolower($servicio) : '',
            'codigo' => ListFilter::text($request, 'codigo', 40),
            'desde' => self::fecha($request, 'desde'),
            'hasta' => self::fecha($request, 'hasta'),
            'seguimiento_pendiente' => $request->boolean('seguimiento_pendiente'),
        ];

        foreach (['estado' => 'estado', 'origen' => 'origen', 'servicio' => 'servicio_uuid', 'codigo' => 'codigo'] as $filtro => $columna) {
            if ($filtros[$filtro] !== '') {
                $query->where($columna, $filtros[$filtro]);
            }
        }
        if ($filtros['desde'] !== '') {
            $query->where('recibida_at', '>=', CarbonImmutable::parse($filtros['desde'], 'America/Lima')->utc());
        }
        if ($filtros['hasta'] !== '') {
            $query->where('recibida_at', '<', CarbonImmutable::parse($filtros['hasta'], 'America/Lima')->addDay()->utc());
        }
        if ($filtros['seguimiento_pendiente']) {
            $query->whereNotNull('proximo_seguimiento')
                ->whereDate('proximo_seguimiento', '<=', CarbonImmutable::now('America/Lima')->toDateString());
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, '-recibida');

        return Listado::responder(Listado::paginar($query, $request), fn (SolicitudCotizacion $solicitud): array => [
            'identificador' => $solicitud->identificador,
            'codigo' => $solicitud->codigo,
            'servicio' => $solicitud->servicio_nombre_historico,
            'servicio_uuid' => $solicitud->servicio_uuid,
            'origen' => $solicitud->origen,
            'estado' => $solicitud->estado,
            'responsable' => $solicitud->responsable?->name,
            'recibida_en' => $solicitud->recibida_at?->toIso8601String(),
            'proximo_seguimiento' => $solicitud->proximo_seguimiento?->toDateString(),
        ], $orden, $filtros);
    }

    public function show(string $identificador): JsonResponse
    {
        $solicitud = $this->buscar($identificador);

        return $this->detalle($solicitud);
    }

    public function store(GuardarCotizacionRequest $request): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $resultado = $this->gestion->crear($request, $request->validated());

        return $this->respuestaDeEscritura($request, $this->buscar($resultado['solicitud']->identificador), $resultado['repetida'] ? 200 : 201, 'La solicitud se registró correctamente.');
    }

    public function update(GuardarCotizacionRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $solicitud = $this->gestion->cambiar($request, $this->buscar($identificador)->identificador, 'editar', $request->validated());

        return $this->respuestaDeEscritura($request, $this->buscar($solicitud->identificador), 200, 'Los datos de la solicitud se actualizaron.');
    }

    public function estado(GestionarCotizacionRequest $request, string $identificador): JsonResponse
    {
        return $this->gestionar($request, $identificador, 'estado', 'El estado de la solicitud se actualizó.');
    }

    public function responsable(GestionarCotizacionRequest $request, string $identificador): JsonResponse
    {
        return $this->gestionar($request, $identificador, 'responsable', 'El responsable de la solicitud se actualizó.');
    }

    public function seguimiento(GestionarCotizacionRequest $request, string $identificador): JsonResponse
    {
        return $this->gestionar($request, $identificador, 'seguimiento', 'El seguimiento se registró.');
    }

    /** Administradores que pueden ser responsables (los mismos que acepta la regla de asignación). */
    public function opcionesResponsables(): JsonResponse
    {
        $opciones = User::query()->role('admin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $usuario): bool => $usuario->can('cotizaciones.ver'))
            ->map(fn (User $usuario): array => ['id' => $usuario->id, 'texto' => $usuario->name])
            ->values()->all();

        return response()->json(['data' => $opciones]);
    }

    /** Servicios que se pueden asociar a una solicitud nueva (no archivados). */
    public function opcionesServicios(): JsonResponse
    {
        $opciones = ServicioTecnologico::query()->where('estado', '!=', 'archivado')->orderBy('nombre')
            ->get(['identificador_publico', 'nombre'])
            ->map(fn (ServicioTecnologico $servicio): array => ['valor' => $servicio->identificador_publico, 'texto' => $servicio->nombre])
            ->all();

        return response()->json(['data' => $opciones]);
    }

    private function gestionar(GestionarCotizacionRequest $request, string $identificador, string $operacion, string $mensaje): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $solicitud = $this->gestion->cambiar($request, $this->buscar($identificador)->identificador, $operacion, $request->validated());

        return $this->respuestaDeEscritura($request, $this->buscar($solicitud->identificador), 200, $mensaje);
    }

    /**
     * Crear, editar o gestionar no concede lectura (mismo contrato que tuvo el módulo Blade, ya retirado): sin
     * `cotizaciones.ver` la respuesta solo confirma la identidad del expediente, también al repetir una creación
     * idempotente, para no entregar datos actuales de contacto, necesidad ni historial a quien no puede leerlos.
     */
    private function respuestaDeEscritura(Request $request, SolicitudCotizacion $solicitud, int $estado, string $mensaje): JsonResponse
    {
        if ($request->user()?->can('cotizaciones.ver') === true) {
            return $this->detalle($solicitud, $estado, $mensaje);
        }

        return response()->json(['message' => $mensaje, 'data' => $solicitud->only(['identificador', 'codigo'])], $estado)
            ->header('ETag', $solicitud->etag())->header('Cache-Control', 'no-store, private');
    }

    private function buscar(string $identificador): SolicitudCotizacion
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return SolicitudCotizacion::query()->with('responsable:id,name')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function detalle(SolicitudCotizacion $solicitud, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $eventos = CotizacionHistorial::query()->where('solicitud_id', $solicitud->id)->orderByDesc('revision')->limit(100)->get();
        $actores = User::query()->whereIn('id', $eventos->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');
        $historial = $eventos
            ->map(function (CotizacionHistorial $evento) use ($actores): array {
                $meta = $evento->metadatos;

                return [
                    'revision' => $evento->revision,
                    'evento' => $evento->evento,
                    'fecha' => $evento->created_at?->toIso8601String(),
                    'actor' => $evento->actor_id === null ? null : ($actores[$evento->actor_id] ?? null),
                    'nota' => $evento->nota,
                    'estado_anterior' => is_string($meta['anterior'] ?? null) ? $meta['anterior'] : null,
                    'estado_nuevo' => is_string($meta['estado'] ?? null) ? $meta['estado'] : null,
                ];
            })->all();

        $datos = [
            'identificador' => $solicitud->identificador,
            'codigo' => $solicitud->codigo,
            'origen' => $solicitud->origen,
            'estado' => $solicitud->estado,
            'recibida_en' => $solicitud->recibida_at?->toIso8601String(),
            'servicio' => $solicitud->servicio_nombre_historico,
            'servicio_uuid' => $solicitud->servicio_uuid,
            'contacto' => [
                'nombre' => $solicitud->contacto_nombre,
                'email' => $solicitud->contacto_email,
                'telefono' => $solicitud->contacto_telefono,
            ],
            'necesidad' => $solicitud->necesidad_cliente,
            'responsable' => $solicitud->responsable === null ? null : ['id' => $solicitud->responsable->id, 'nombre' => $solicitud->responsable->name],
            'ultimo_seguimiento' => $solicitud->ultimo_seguimiento_at?->toIso8601String(),
            'proximo_seguimiento' => $solicitud->proximo_seguimiento?->toDateString(),
            'revision' => $solicitud->etag(),
            'historial' => $historial,
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $solicitud->etag());
    }

    /** Fecha AAAA-MM-DD válida o cadena vacía. */
    private static function fecha(Request $request, string $campo): string
    {
        $valor = ListFilter::text($request, $campo, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))
            ? $valor
            : '';
    }
}
