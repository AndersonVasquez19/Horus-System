<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarAulaRequest;
use App\Models\Aula;
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
 * Aulas del panel (`infraestructura.ver|gestionar`). Cada aula pertenece a una sede (fijada al crearla). No se eliminan: se
 * desactivan.
 */
final class AulaController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['codigo' => 'codigo', 'nombre' => 'nombre', 'capacidad' => 'capacidad', 'creado' => 'created_at'];

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
        $sede = $request->query('sede');
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Sede::ESTADOS),
            'sede' => is_string($sede) && preg_match('/\A[0-9a-f-]{36}\z/i', $sede) === 1 ? strtolower($sede) : '',
        ];
        $consulta = Aula::query()->with('sede:id,identificador,codigo,nombre');
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['codigo', 'nombre'], $filtros['busqueda']);
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['sede'] !== '') {
            $consulta->whereHas('sede', fn ($q) => $q->where('identificador', $filtros['sede']));
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'codigo');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Aula $a): array => $this->representar($a), $orden, $filtros);
    }

    public function show(string $aula): JsonResponse
    {
        return response()->json(['data' => $this->representar($this->buscar($aula))])->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarAulaRequest $request): JsonResponse
    {
        $aula = $this->gestion->crearAula($request, $request->validated());

        return $this->respuesta($this->buscar($aula->identificador), 201, 'El aula se registró correctamente.');
    }

    public function update(GuardarAulaRequest $request, string $aula): JsonResponse
    {
        $modelo = $this->gestion->actualizarAula($request, $this->buscar($aula)->identificador, $request->validated());

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'Los datos del aula se actualizaron.');
    }

    public function estado(Request $request, string $aula): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Sede::ESTADOS)]], ['estado.*' => 'Estado no permitido.']);
        $modelo = $this->gestion->cambiarEstadoAula($request, $this->buscar($aula)->identificador, $datos['estado']);

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'El estado del aula se actualizó.');
    }

    private function buscar(string $identificador): Aula
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Aula::query()->with('sede:id,identificador,codigo,nombre')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function representar(Aula $a): array
    {
        return [
            'identificador' => $a->identificador,
            'codigo' => $a->codigo,
            'nombre' => $a->nombre,
            'capacidad' => $a->capacidad,
            'estado' => $a->estado,
            'sede' => $a->sede === null ? null : ['identificador' => $a->sede->identificador, 'codigo' => $a->sede->codigo, 'nombre' => $a->sede->nombre],
            'creado_en' => $a->created_at?->toIso8601String(),
        ];
    }

    private function respuesta(Aula $a, int $estado, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->representar($a)], $estado)->header('Cache-Control', 'no-store, private');
    }
}
