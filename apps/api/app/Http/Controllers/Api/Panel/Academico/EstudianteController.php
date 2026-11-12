<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstudianteRequest;
use App\Http\Requests\UpdateEstudianteRequest;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\GestionEstudiantes;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\HistorialAuditoria;
use App\Support\Panel\Listado;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Estudiantes del panel académico. Mismos permisos, Form Requests y reglas que Admin\EstudianteController:
 * las escrituras pasan por {@see GestionEstudiantes} (transacción, bloqueo y auditoría).
 */
final class EstudianteController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['activo', 'inactivo'];

    /** Acciones de auditoría que forman el historial de un estudiante. */
    private const ACCIONES_HISTORIAL = ['estudiante_creado', 'estudiante_actualizado', 'estudiante_eliminado', 'estudiante_restaurado'];

    private const ORDENABLES = [
        'apellidos' => 'apellidos',
        'nombres' => 'nombres',
        'dni' => 'dni',
        'creado' => 'created_at',
    ];

    public function __construct(private readonly GestionEstudiantes $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:estudiantes.ver', only: ['index', 'show']),
            new Middleware('permission:estudiantes.crear', only: ['store']),
            new Middleware('permission:estudiantes.editar', only: ['update']),
            new Middleware('permission:estudiantes.eliminar', only: ['destroy']),
            new Middleware('permission:estudiantes.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Estudiante::query();

        $eliminados = $request->boolean('eliminados');
        if ($eliminados) {
            $query->onlyTrashed();
        }

        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'eliminados' => $eliminados,
        ];

        BusquedaLike::contiene($query, ['dni', 'nombres', 'apellidos'], $filtros['busqueda']);
        if ($filtros['estado'] !== '') {
            $query->where('estado', $filtros['estado']);
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, 'apellidos');
        if (ltrim($orden, '-') === 'apellidos') {
            // Mismo criterio que el listado Blade: apellidos y, dentro de ellos, nombres.
            $query->reorder()
                ->orderBy('apellidos', str_starts_with($orden, '-') ? 'desc' : 'asc')
                ->orderBy('nombres', str_starts_with($orden, '-') ? 'desc' : 'asc')
                ->orderBy('id', str_starts_with($orden, '-') ? 'desc' : 'asc');
        }

        return Listado::responder(
            Listado::paginar($query, $request),
            fn (Estudiante $estudiante): array => self::resumen($estudiante),
            $orden,
            $filtros,
        );
    }

    public function show(Request $request, int $estudiante): JsonResponse
    {
        $modelo = Estudiante::withTrashed()->findOrFail($estudiante);

        return response()->json(['data' => $this->detalle($modelo, $request->user()?->can('matriculas.ver') === true)]);
    }

    public function store(StoreEstudianteRequest $request): JsonResponse
    {
        $estudiante = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El estudiante se registró correctamente.',
            'data' => $this->detalle($estudiante->refresh(), false),
        ], 201);
    }

    public function update(UpdateEstudianteRequest $request, Estudiante $estudiante): JsonResponse
    {
        $actualizado = $this->gestion->actualizar($estudiante, $request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'Los datos del estudiante se actualizaron correctamente.',
            'data' => $this->detalle($actualizado->refresh(), $request->user()?->can('matriculas.ver') === true),
        ]);
    }

    public function destroy(Request $request, Estudiante $estudiante): JsonResponse
    {
        $vigentes = $this->gestion->eliminar($estudiante, AuditoriaAcademica::actorDe($request));

        if ($vigentes > 0) {
            abort(409, GestionEstudiantes::mensajeMatriculasVigentes($vigentes));
        }

        return response()->json(['message' => 'El estudiante se desactivó correctamente.']);
    }

    public function restaurar(Request $request, int $estudiante): JsonResponse
    {
        $restaurado = $this->gestion->restaurar($estudiante, AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El estudiante se restauró correctamente.',
            'data' => self::resumen($restaurado),
        ]);
    }

    /** @return array<string, mixed> */
    private static function resumen(Estudiante $estudiante): array
    {
        return [
            'id' => $estudiante->id,
            'dni' => $estudiante->dni,
            'nombres' => $estudiante->nombres,
            'apellidos' => $estudiante->apellidos,
            'email' => $estudiante->email,
            'telefono' => $estudiante->telefono,
            'estado' => $estudiante->estado,
            'eliminado' => $estudiante->trashed(),
            'creado_en' => $estudiante->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(Estudiante $estudiante, bool $conMatriculas): array
    {
        $nacimiento = $estudiante->getAttribute('fecha_nacimiento');
        $datos = [
            ...self::resumen($estudiante),
            'fecha_nacimiento' => $nacimiento instanceof CarbonInterface ? $nacimiento->toDateString() : null,
            'direccion' => $estudiante->direccion,
            'actualizado_en' => $estudiante->updated_at?->toIso8601String(),
            'historial' => HistorialAuditoria::de(Estudiante::class, $estudiante->id, self::ACCIONES_HISTORIAL),
        ];

        // Las matrículas solo con `matriculas.ver` (el Blade las mostraba a todo el que veía al estudiante, pero el
        // panel nuevo separa los módulos). Sin montos ni observaciones: eso pertenece al detalle de la matrícula.
        if ($conMatriculas) {
            $datos['matriculas'] = $estudiante->matriculas()
                ->with('curso:id,codigo,nombre,deleted_at')
                ->latest('fecha_matricula')
                ->latest('id')
                ->get(['id', 'curso_id', 'estudiante_id', 'estado', 'fecha_matricula'])
                ->map(fn (Matricula $matricula): array => [
                    'id' => $matricula->id,
                    'curso' => $matricula->curso === null ? null : [
                        'id' => $matricula->curso->id,
                        'codigo' => $matricula->curso->codigo,
                        'nombre' => $matricula->curso->nombre,
                    ],
                    'estado' => $matricula->estado,
                    'fecha' => $matricula->fecha_matricula->toDateString(),
                ])
                ->all();
        }

        return $datos;
    }
}
