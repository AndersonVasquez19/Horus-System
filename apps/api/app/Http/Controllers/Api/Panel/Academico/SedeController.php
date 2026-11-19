<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarSedeRequest;
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
 * Sedes del panel (`infraestructura.ver|gestionar`). Toda escritura pasa por {@see GestionInfraestructura}. No se eliminan:
 * se desactivan.
 */
final class SedeController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['codigo' => 'codigo', 'nombre' => 'nombre', 'creado' => 'created_at'];

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
        ];
        $consulta = Sede::query()->withCount('aulas');
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['codigo', 'nombre', 'ciudad'], $filtros['busqueda']);
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'codigo');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Sede $s): array => $this->representar($s), $orden, $filtros);
    }

    public function show(string $sede): JsonResponse
    {
        return response()->json(['data' => $this->representar($this->buscar($sede))])->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarSedeRequest $request): JsonResponse
    {
        $sede = $this->gestion->crearSede($request, $request->validated());

        return $this->respuesta($this->buscar($sede->identificador), 201, 'La sede se registró correctamente.');
    }

    public function update(GuardarSedeRequest $request, string $sede): JsonResponse
    {
        $modelo = $this->gestion->actualizarSede($request, $this->buscar($sede)->identificador, $request->validated());

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'Los datos de la sede se actualizaron.');
    }

    public function estado(Request $request, string $sede): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Sede::ESTADOS)]], ['estado.*' => 'Estado no permitido.']);
        $modelo = $this->gestion->cambiarEstadoSede($request, $this->buscar($sede)->identificador, $datos['estado']);

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'El estado de la sede se actualizó.');
    }

    private function buscar(string $identificador): Sede
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Sede::query()->withCount('aulas')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function representar(Sede $s): array
    {
        return [
            'identificador' => $s->identificador,
            'codigo' => $s->codigo,
            'nombre' => $s->nombre,
            'direccion' => $s->direccion,
            'ciudad' => $s->ciudad,
            'estado' => $s->estado,
            'aulas' => (int) ($s->aulas_count ?? 0),
            'creado_en' => $s->created_at?->toIso8601String(),
        ];
    }

    private function respuesta(Sede $s, int $estado, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->representar($s)], $estado)->header('Cache-Control', 'no-store, private');
    }
}
