<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPlataformaVirtualRequest;
use App\Models\PlataformaVirtual;
use App\Models\Sede;
use App\Services\GestionInfraestructura;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Plataformas virtuales externas (`infraestructura.ver|gestionar`). Solo se catalogan (nombre y tipo): sin credenciales,
 * claves de API ni enlaces. No se eliminan: se desactivan.
 */
final class PlataformaVirtualController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['nombre' => 'nombre', 'tipo' => 'tipo', 'creado' => 'created_at'];

    public function __construct(private readonly GestionInfraestructura $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:infraestructura.ver', only: ['index', 'show']),
            new Middleware('permission:infraestructura.gestionar', only: ['store', 'update', 'estado']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Sede::ESTADOS),
            'tipo' => ListFilter::in($request, 'tipo', PlataformaVirtual::TIPOS),
        ];
        $consulta = PlataformaVirtual::query();
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['nombre'], $filtros['busqueda']);
        }
        foreach (['estado', 'tipo'] as $campo) {
            if ($filtros[$campo] !== '') {
                $consulta->where($campo, $filtros[$campo]);
            }
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'nombre');

        return Listado::responder(Listado::paginar($consulta, $request), fn (PlataformaVirtual $p): array => $this->representar($p), $orden, $filtros);
    }

    public function show(string $plataforma): JsonResponse
    {
        return response()->json(['data' => $this->representar($this->buscar($plataforma))])->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarPlataformaVirtualRequest $request): JsonResponse
    {
        $plataforma = $this->gestion->crearPlataforma($request, $request->validated());

        return $this->respuesta($this->buscar($plataforma->identificador), 201, 'La plataforma se registró correctamente.');
    }

    public function update(GuardarPlataformaVirtualRequest $request, string $plataforma): JsonResponse
    {
        $modelo = $this->gestion->actualizarPlataforma($request, $this->buscar($plataforma)->identificador, $request->validated());

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'Los datos de la plataforma se actualizaron.');
    }

    public function estado(Request $request, string $plataforma): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Sede::ESTADOS)]], ['estado.*' => 'Estado no permitido.']);
        $modelo = $this->gestion->cambiarEstadoPlataforma($request, $this->buscar($plataforma)->identificador, $datos['estado']);

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'El estado de la plataforma se actualizó.');
    }

    private function buscar(string $identificador): PlataformaVirtual
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return PlataformaVirtual::query()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function representar(PlataformaVirtual $p): array
    {
        return [
            'identificador' => $p->identificador,
            'nombre' => $p->nombre,
            'tipo' => $p->tipo,
            'estado' => $p->estado,
            'creado_en' => $p->created_at?->toIso8601String(),
        ];
    }

    private function respuesta(PlataformaVirtual $p, int $estado, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->representar($p)], $estado)->header('Cache-Control', 'no-store, private');
    }
}
