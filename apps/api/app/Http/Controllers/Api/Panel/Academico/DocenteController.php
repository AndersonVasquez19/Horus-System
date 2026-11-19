<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarDocenteRequest;
use App\Models\Convocatoria;
use App\Models\Docente;
use App\Models\User;
use App\Services\GestionDocentes;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Docentes del panel (`docentes.ver|crear|editar|eliminar|restaurar`). Toda escritura pasa por {@see GestionDocentes}. El
 * listado minimiza datos (sin correo ni teléfono); el detalle los incluye. Los eliminados solo los ve quien puede restaurarlos.
 */
final class DocenteController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['apellidos' => 'apellidos', 'dni' => 'dni', 'creado' => 'created_at'];

    public function __construct(private readonly GestionDocentes $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:docentes.ver', only: ['index', 'show']),
            new Middleware('permission:docentes.crear', only: ['store']),
            new Middleware('permission:docentes.editar', only: ['update']),
            new Middleware('permission:docentes.eliminar', only: ['destroy']),
            new Middleware('permission:docentes.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Docente::ESTADOS),
            'eliminados' => $request->boolean('eliminados') && $usuario->can('docentes.restaurar'),
        ];

        $consulta = Docente::query();
        if ($filtros['eliminados']) {
            $consulta->onlyTrashed();
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['dni', 'nombres', 'apellidos', 'especialidad'], $filtros['busqueda']);
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'apellidos');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Docente $d): array => $this->resumen($d), $orden, $filtros);
    }

    public function show(Request $request, string $docente): JsonResponse
    {
        return response()->json(['data' => $this->detalle($this->buscar($docente, $request))])->header('Cache-Control', 'no-store, private');
    }

    public function store(GuardarDocenteRequest $request): JsonResponse
    {
        $docente = $this->gestion->crear($request, $request->validated());

        return $this->respuesta($this->buscar($docente->identificador, $request), 201, 'El docente se registró correctamente.');
    }

    public function update(GuardarDocenteRequest $request, string $docente): JsonResponse
    {
        $modelo = $this->gestion->actualizar($request, $this->buscar($docente, $request)->identificador, $request->validated());

        return $this->respuesta($this->buscar($modelo->identificador, $request), 200, 'Los datos del docente se actualizaron.');
    }

    public function destroy(Request $request, string $docente): JsonResponse
    {
        $modelo = $this->gestion->eliminar($request, $this->buscar($docente, $request)->identificador);

        return $this->respuesta($this->buscar($modelo->identificador, $request), 200, 'El docente se eliminó.');
    }

    public function restaurar(Request $request, string $docente): JsonResponse
    {
        $modelo = $this->gestion->restaurar($request, $this->buscar($docente, $request)->identificador);

        return $this->respuesta($this->buscar($modelo->identificador, $request), 200, 'El docente se restauró.');
    }

    /** Un docente eliminado solo se consulta con `docentes.restaurar`; para el resto responde 404. */
    private function buscar(string $identificador, Request $request): Docente
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $consulta = Docente::query();
        if ($request->user()?->can('docentes.restaurar')) {
            $consulta->withTrashed();
        }

        return $consulta->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resumen(Docente $d): array
    {
        return [
            'identificador' => $d->identificador,
            'dni' => $d->dni,
            'nombres' => $d->nombres,
            'apellidos' => $d->apellidos,
            'especialidad' => $d->especialidad,
            'estado' => $d->estado,
            'eliminado' => $d->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(Docente $d): array
    {
        $vigentes = $d->convocatorias()->whereIn('convocatorias.estado', Convocatoria::VIGENTES)->count();

        return [
            ...$this->resumen($d),
            'email' => $d->email,
            'telefono' => $d->telefono,
            'ediciones_vigentes' => $vigentes,
            'creado_en' => $d->created_at?->toIso8601String(),
        ];
    }

    private function respuesta(Docente $d, int $estado, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $this->detalle($d)], $estado)->header('Cache-Control', 'no-store, private');
    }
}
