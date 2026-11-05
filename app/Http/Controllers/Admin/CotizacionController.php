<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarCotizacionRequest;
use App\Http\Requests\GuardarCotizacionRequest;
use App\Models\CotizacionHistorial;
use App\Models\SolicitudCotizacion;
use App\Services\GestionCotizaciones;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class CotizacionController extends Controller implements HasMiddleware
{
    public function __construct(private GestionCotizaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('role:admin'),
            new Middleware('permission:cotizaciones.ver', only: ['index', 'show', 'historial']),
            new Middleware('permission:cotizaciones.crear', only: ['store']),
            new Middleware('permission:cotizaciones.editar', only: ['update']),
            new Middleware('permission:cotizaciones.gestionar', only: ['estado', 'responsable', 'seguimiento'])];
    }

    public function index(Request $request): JsonResponse|Response
    {
        $datos = $request->validate([
            'estado' => ['sometimes', Rule::in(['recibida', 'en_revision'])],
            'responsable_id' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'servicio_uuid' => ['sometimes', 'uuid'], 'codigo' => ['sometimes', 'string', 'max:40'],
            'recibida_desde' => ['sometimes', 'date_format:Y-m-d'],
            'recibida_hasta' => ['sometimes', 'date_format:Y-m-d', ...($request->has('recibida_desde') ? ['after_or_equal:recibida_desde'] : [])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $consulta = SolicitudCotizacion::query()->select(['identificador', 'codigo', 'recibida_at', 'estado', 'responsable_id',
            'servicio_uuid', 'servicio_nombre_historico', 'proximo_seguimiento', 'revision']);
        foreach (['estado', 'responsable_id', 'servicio_uuid', 'codigo'] as $campo) {
            if (isset($datos[$campo])) {
                $consulta->where($campo, $datos[$campo]);
            }
        }
        if (isset($datos['recibida_desde'])) {
            $consulta->where('recibida_at', '>=', CarbonImmutable::parse($datos['recibida_desde'], 'America/Lima')->utc());
        }
        if (isset($datos['recibida_hasta'])) {
            $consulta->where('recibida_at', '<', CarbonImmutable::parse($datos['recibida_hasta'], 'America/Lima')->addDay()->utc());
        }

        $solicitudes = $consulta->orderBy('id')->paginate((int) ($datos['por_pagina'] ?? 15));
        if (! $request->expectsJson()) {
            return response()->view('admin.cotizaciones.index', ['solicitudes' => $solicitudes->appends($datos), 'filtros' => $datos])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json($solicitudes)
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarCotizacionRequest $request): JsonResponse
    {
        $resultado = $this->gestion->crear($request, $request->validated());

        return $this->recibo($resultado['solicitud'], $resultado['repetida'] ? 200 : 201);
    }

    public function show(SolicitudCotizacion $cotizacion): JsonResponse
    {
        return response()->json(['solicitud' => $cotizacion->only([
            'identificador', 'codigo', 'recibida_at', 'servicio_uuid', 'servicio_nombre_historico',
            'necesidad_cliente', 'contacto_nombre', 'contacto_email', 'contacto_telefono', 'estado',
            'responsable_id', 'creado_por', 'ultimo_seguimiento_at', 'proximo_seguimiento', 'revision', 'created_at', 'updated_at',
        ])])->header('ETag', $cotizacion->etag())->header('Cache-Control', 'no-store, private');
    }

    public function historial(Request $request, SolicitudCotizacion $cotizacion): JsonResponse
    {
        $datos = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return response()->json(CotizacionHistorial::query()->select(['revision', 'evento', 'actor_id', 'metadatos', 'nota', 'created_at'])
            ->where('solicitud_id', $cotizacion->id)->orderBy('revision')->paginate((int) ($datos['por_pagina'] ?? 15)))
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(GuardarCotizacionRequest $request, SolicitudCotizacion $cotizacion): JsonResponse
    {
        return $this->recibo($this->gestion->cambiar($request, $cotizacion->identificador, 'editar', $request->validated()));
    }

    public function estado(GestionarCotizacionRequest $request, SolicitudCotizacion $cotizacion): JsonResponse
    {
        return $this->recibo($this->gestion->cambiar($request, $cotizacion->identificador, 'estado', $request->validated()));
    }

    public function responsable(GestionarCotizacionRequest $request, SolicitudCotizacion $cotizacion): JsonResponse
    {
        return $this->recibo($this->gestion->cambiar($request, $cotizacion->identificador, 'responsable', $request->validated()));
    }

    public function seguimiento(GestionarCotizacionRequest $request, SolicitudCotizacion $cotizacion): JsonResponse
    {
        return $this->recibo($this->gestion->cambiar($request, $cotizacion->identificador, 'seguimiento', $request->validated()));
    }

    private function recibo(SolicitudCotizacion $solicitud, int $status = 200): JsonResponse
    {
        // Crear/gestionar no concede lectura: el recibo solo confirma la identidad del expediente.
        return response()->json(['solicitud' => $solicitud->only(['identificador', 'codigo'])], $status)
            ->header('ETag', $solicitud->etag())->header('Cache-Control', 'no-store, private');
    }
}
