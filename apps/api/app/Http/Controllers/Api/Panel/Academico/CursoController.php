<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursoRequest;
use App\Http\Requests\UpdateCursoRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Services\GestionCursos;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\HistorialAuditoria;
use App\Support\Panel\Listado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Cursos del panel académico (lectura). Mismos filtros y permisos que el listado Blade (`cursos.ver`).
 */
final class CursoController extends Controller implements HasMiddleware
{
    public const CATEGORIAS = ['tecnologia', 'educacion', 'salud', 'gestion', 'otros'];

    public const ESTADOS = ['activo', 'inactivo', 'archivado'];

    public const MODALIDADES = ['presencial', 'semipresencial', 'virtual', 'hibrido'];

    /** Acciones de auditoría que forman el historial de un curso (incluida su ficha pública). */
    private const ACCIONES_HISTORIAL = [
        'curso_creado', 'curso_actualizado', 'curso_archivado', 'curso_restaurado', 'curso_publicado', 'curso_despublicado',
    ];

    private const ORDENABLES = [
        'nombre' => 'nombre',
        'codigo' => 'codigo',
        'cupos_disponibles' => 'cupos_disponibles',
        'creado' => 'created_at',
    ];

    public function __construct(private readonly GestionCursos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:cursos.ver', only: ['index', 'show']),
            new Middleware('permission:cursos.crear', only: ['store']),
            new Middleware('permission:cursos.editar', only: ['update']),
            new Middleware('permission:cursos.eliminar', only: ['destroy']),
            new Middleware('permission:cursos.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Curso::query();

        $eliminados = $request->boolean('eliminados');
        if ($eliminados) {
            $query->onlyTrashed();
        }

        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'categoria' => ListFilter::in($request, 'categoria', self::CATEGORIAS),
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'modalidad' => ListFilter::in($request, 'modalidad', self::MODALIDADES),
            'eliminados' => $eliminados,
        ];

        BusquedaLike::contiene($query, ['codigo', 'nombre'], $filtros['busqueda']);
        foreach (['categoria', 'estado', 'modalidad'] as $campo) {
            if ($filtros[$campo] !== '') {
                $query->where($campo, $filtros[$campo]);
            }
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, 'nombre');

        return Listado::responder(Listado::paginar($query, $request), fn (Curso $curso): array => self::resumen($curso), $orden, $filtros);
    }

    public function store(StoreCursoRequest $request): JsonResponse
    {
        $curso = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El curso se registró correctamente.',
            'data' => $this->detalle($curso->refresh()),
        ], 201);
    }

    public function update(UpdateCursoRequest $request, Curso $curso): JsonResponse
    {
        $actualizado = $this->gestion->actualizar($curso, $request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'Los datos del curso se actualizaron correctamente.',
            'data' => $this->detalle($actualizado->refresh()),
        ]);
    }

    public function destroy(Request $request, Curso $curso): JsonResponse
    {
        $vigentes = $this->gestion->archivar($curso, AuditoriaAcademica::actorDe($request));

        if ($vigentes > 0) {
            abort(409, GestionCursos::mensajeMatriculasVigentes($vigentes));
        }

        return response()->json(['message' => 'El curso se archivó correctamente.']);
    }

    public function restaurar(Request $request, int $curso): JsonResponse
    {
        $restaurado = $this->gestion->restaurar($curso, AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El curso se restauró correctamente.',
            'data' => self::resumen($restaurado),
        ]);
    }

    public function show(int $curso): JsonResponse
    {
        return response()->json(['data' => $this->detalle(Curso::withTrashed()->findOrFail($curso))]);
    }

    /** @return array<string, mixed> */
    private function detalle(Curso $modelo): array
    {
        $estudiantes = $modelo->estudiantes()
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get(['estudiantes.id', 'estudiantes.nombres', 'estudiantes.apellidos']);

        return [
            ...self::resumen($modelo),
            'descripcion' => $modelo->descripcion,
            'duracion_horas' => $modelo->duracion_horas,
            'precio' => $modelo->precio,
            'horario' => $modelo->horario,
            'actualizado_en' => $modelo->updated_at?->toIso8601String(),
            'matriculas_activas' => $estudiantes->count(),
            // Minimizado: nombre y estado de la matrícula; sin documento, contacto, montos ni observaciones.
            'estudiantes' => $estudiantes->map(fn (Estudiante $estudiante): array => [
                'id' => $estudiante->id,
                'nombre' => trim($estudiante->nombres.' '.$estudiante->apellidos),
                'estado_matricula' => self::pivote($estudiante, 'estado'),
                'fecha_matricula' => substr((string) self::pivote($estudiante, 'fecha_matricula'), 0, 10) ?: null,
            ])->all(),
            'historial' => HistorialAuditoria::de(Curso::class, $modelo->id, self::ACCIONES_HISTORIAL),
        ];
    }

    /** Campo de la matrícula (tabla intermedia) cargada con la relación `estudiantes` del curso. */
    private static function pivote(Estudiante $estudiante, string $campo): mixed
    {
        $pivote = $estudiante->relationLoaded('pivot') ? $estudiante->getRelation('pivot') : null;

        return $pivote instanceof Model ? $pivote->getAttribute($campo) : null;
    }

    /** @return array<string, mixed> */
    private static function resumen(Curso $curso): array
    {
        return [
            'id' => $curso->id,
            'codigo' => $curso->codigo,
            'nombre' => $curso->nombre,
            'categoria' => $curso->categoria,
            'modalidad' => $curso->modalidad,
            'estado' => $curso->estado,
            'cupos_maximos' => $curso->cupos_maximos,
            'cupos_disponibles' => $curso->cupos_disponibles,
            'eliminado' => $curso->trashed(),
            'creado_en' => $curso->created_at?->toIso8601String(),
        ];
    }
}
