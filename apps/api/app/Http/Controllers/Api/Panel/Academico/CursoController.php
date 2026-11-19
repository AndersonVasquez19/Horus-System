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
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\HistorialAuditoria;
use App\Support\Panel\Listado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        'precio' => 'precio',
        'duracion' => 'duracion_horas',
        'cupos_disponibles' => 'cupos_disponibles',
        'creado' => 'created_at',
    ];

    public function __construct(private readonly GestionCursos $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:cursos.ver', only: ['index', 'show', 'exportar']),
            new Middleware('permission:cursos.crear', only: ['store', 'duplicar']),
            new Middleware('permission:cursos.editar', only: ['update']),
            new Middleware('permission:cursos.eliminar', only: ['destroy']),
            new Middleware('permission:cursos.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$query, $filtros, $orden] = $this->consultaFiltrada($request);

        return Listado::responder(Listado::paginar($query, $request), fn (Curso $curso): array => self::resumen($curso), $orden, $filtros);
    }

    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        [$query, $filtros] = $this->consultaFiltrada($request);
        $filas = $query->get()->map(fn (Curso $c): array => [
            $c->id,
            $c->codigo,
            $c->nombre,
            $c->categoria,
            $c->modalidad,
            $c->duracion_horas,
            number_format((float) $c->precio, 2, '.', ''),
            $c->cupos_disponibles,
            $c->cupos_maximos,
            $c->publicacion?->publicada === true ? 'Sí' : 'No',
            ucfirst((string) $c->estado),
        ]);

        return ExportacionListado::responder(
            'cursos',
            'Cursos',
            ExportacionListado::formato($request),
            ['ID', 'Código', 'Nombre', 'Categoría', 'Modalidad', 'Duración (h)', 'Precio (PEN)', 'Cupos disponibles', 'Cupos máximos', 'Publicado', 'Estado'],
            $filas,
            'cursos_exportados',
            Curso::class,
            AuditoriaAcademica::actorDe($request),
            ['estado' => $filtros['estado'] ?: null, 'eliminados' => $filtros['eliminados'], 'con_busqueda' => $filtros['busqueda'] !== ''],
        );
    }

    /** @return array{0: Builder<Curso>, 1: array<string, mixed>, 2: string} */
    private function consultaFiltrada(Request $request): array
    {
        $query = Curso::query()->with('publicacion:id,curso_id,publicada');

        $eliminados = $request->boolean('eliminados');
        if ($eliminados) {
            $query->onlyTrashed();
        }

        $publicado = ListFilter::in($request, 'publicado', ['1', '0']);
        $conCupos = $request->boolean('con_cupos');

        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'categoria' => ListFilter::in($request, 'categoria', self::CATEGORIAS),
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS),
            'modalidad' => ListFilter::in($request, 'modalidad', self::MODALIDADES),
            'con_cupos' => $conCupos,
            'publicado' => $publicado,
            'eliminados' => $eliminados,
        ];

        BusquedaLike::contiene($query, ['codigo', 'nombre'], $filtros['busqueda']);
        foreach (['categoria', 'estado', 'modalidad'] as $campo) {
            if ($filtros[$campo] !== '') {
                $query->where($campo, $filtros[$campo]);
            }
        }
        if ($conCupos) {
            $query->where('cupos_disponibles', '>', 0);
        }
        if ($publicado === '1') {
            $query->whereHas('publicacion', fn ($q) => $q->where('publicada', true));
        } elseif ($publicado === '0') {
            $query->whereDoesntHave('publicacion', fn ($q) => $q->where('publicada', true));
        }

        $orden = Listado::ordenar($query, $request, self::ORDENABLES, 'nombre');

        return [$query, $filtros, $orden];
    }

    public function store(StoreCursoRequest $request): JsonResponse
    {
        $curso = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El curso se registró correctamente.',
            'data' => $this->detalle($curso->refresh()),
        ], 201);
    }

    public function duplicar(Request $request, Curso $curso): JsonResponse
    {
        $copia = $this->gestion->duplicar($curso, AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'El curso se duplicó correctamente.',
            'data' => $this->detalle($copia->refresh()),
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
        $modelo->loadMissing('publicacion:id,curso_id,publicada');
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
            'enlace_grupo_whatsapp' => Curso::enlaceGrupoVisible($modelo->enlace_grupo_whatsapp),
            'actualizado_en' => $modelo->updated_at?->toIso8601String(),
            'matriculas_activas' => $estudiantes->count(),
            // Minimizado: nombre y estado de la matrícula; sin documento, contacto, montos ni observaciones.
            'estudiantes' => $estudiantes->map(fn (Estudiante $estudiante): array => [
                'id' => $estudiante->id,
                'matricula_id' => self::pivote($estudiante, 'id'),
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
            'duracion_horas' => $curso->duracion_horas,
            'precio' => $curso->precio,
            'modalidad' => $curso->modalidad,
            'estado' => $curso->estado,
            'publicado' => $curso->relationLoaded('publicacion') && (bool) ($curso->publicacion?->publicada ?? false),
            'enlace_grupo_whatsapp' => Curso::enlaceGrupoVisible($curso->enlace_grupo_whatsapp),
            'cupos_maximos' => $curso->cupos_maximos,
            'cupos_disponibles' => $curso->cupos_disponibles,
            'eliminado' => $curso->trashed(),
            'creado_en' => $curso->created_at?->toIso8601String(),
        ];
    }
}
