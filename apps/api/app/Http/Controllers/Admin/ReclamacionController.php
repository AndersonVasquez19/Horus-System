<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GestionarReclamacionRequest;
use App\Models\Reclamacion;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Services\OperacionesReclamacion;
use App\Support\ConsultaReclamaciones;
use App\Support\HojaReclamacion;
use App\Support\Reclamaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bandeja administrativa del Libro de Reclamaciones: consulta e impresión (E3) y gestión del caso (E4).
 *
 * Permite a Gerencia y personal con permiso `reclamaciones.ver` consultar las quejas
 * y reclamos recibidos, aplicar filtros por tipo, estado, fechas y texto, revisar
 * el detalle completo de cada registro (con su historial y envíos) e imprimir la hoja.
 * Ninguna de estas peticiones GET modifica reclamaciones: la única escritura es la auditoría del acceso.
 *
 * La gestión (`gestionar`) solo delega en {@see GestionReclamaciones}, que comprueba permiso, estado y reglas
 * bajo bloqueo y deja historial y auditoría. Este controlador no decide reglas del libro.
 */
class ReclamacionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly GestionReclamaciones $gestion,
        private readonly OperacionesReclamacion $operaciones,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reclamaciones.ver', only: ['index', 'show', 'hoja']),
            // Permiso fino por operación: GestionarReclamacionRequest::authorize() y el propio servicio.
            new Middleware('permission:reclamaciones.gestionar|reclamaciones.responder', only: ['gestionar']),
        ];
    }

    public function index(Request $request): Response
    {
        $filtros = ConsultaReclamaciones::validar($request);

        $consulta = Reclamacion::query();
        ConsultaReclamaciones::aplicar($consulta, $filtros);

        $reclamaciones = $consulta->orderByDesc('id')->paginate(15)->withQueryString();

        // Constancia del acceso a la bandeja: solo los nombres de los filtros usados y la página, nunca sus valores.
        $this->gestion->registrarConsultaBandeja($this->usuario($request), ConsultaReclamaciones::usados($filtros), $reclamaciones->currentPage());

        return response()->view('admin.reclamaciones.index', [
            'reclamaciones' => $reclamaciones,
            'filtros' => $filtros,
            'metricas' => ConsultaReclamaciones::metricas(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->consultar($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.show', [
            'reclamacion' => $registro,
            // Indicadores de solo lectura: se calculan para mostrarlos, sin persistir nada.
            'avisoPlazo' => $this->gestion->umbralAviso($registro),
            'propuestaVencida' => $this->gestion->propuestaVencidaSinProcesar($registro),
            'responsables' => $this->operaciones->responsablesDisponibles(),
            'correoActivo' => Reclamaciones::envioHabilitado(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Operaciones de gestión (E4). Un 409 del servicio (el caso ya no admite la operación) vuelve al detalle con
     * el mensaje fijo del servicio, que no contiene datos personales; 403 y 404 siguen su curso normal.
     */
    public function gestionar(GestionarReclamacionRequest $request, Reclamacion $reclamacion): RedirectResponse|JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        try {
            $mensaje = $this->operaciones->ejecutar($this->usuario($request), $reclamacion, $datos);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return redirect()->route('admin.reclamaciones.show', $reclamacion)
                ->withErrors(['gestion' => $e->getMessage()])
                ->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje]);
        }

        return redirect()->route('admin.reclamaciones.show', $reclamacion)->with('success', $mensaje);
    }

    /** Hoja de reclamación imprimible: el texto oficial es el de {@see HojaReclamacion} (el mismo de la copia al consumidor). */
    public function hoja(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->imprimir($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.hoja', [
            'reclamacion' => $registro,
            'hoja' => HojaReclamacion::cuerpo($registro),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function usuario(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
