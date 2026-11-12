<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Admin\MatriculaController as MatriculaBlade;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\AuditLog;
use App\Models\Matricula;
use App\Services\GestionMatriculas;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\OpcionesMatricula;
use App\Support\Panel\Listado;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Matrículas del panel académico.
 *
 * Toda escritura pasa por {@see GestionMatriculas}, sin atajos: transacción, protocolo de bloqueo (cursos por id →
 * estudiantes por id → matrícula), relectura de elegibilidad, duplicado y cupo bajo bloqueo, y auditoría como
 * última escritura. La validación HTTP son los mismos Form Requests del Blade. Los permisos son los mismos por
 * acción (`matriculas.ver|crear|editar|anular|eliminar|restaurar|exportar`).
 */
final class MatriculaController extends Controller implements HasMiddleware
{
    private const ORDENABLES = [
        'fecha' => 'fecha_matricula',
        'creado' => 'created_at',
        'estado' => 'estado',
    ];

    /** Acciones de auditoría que forman el historial de una matrícula. */
    private const ACCIONES_HISTORIAL = [
        'matricula_creada',
        'matricula_actualizada',
        'matricula_anulada',
        'matricula_eliminada',
        'matricula_restaurada',
    ];

    public function __construct(private readonly GestionMatriculas $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:matriculas.ver', only: ['index', 'show']),
            new Middleware('permission:matriculas.crear', only: ['store']),
            new Middleware('permission:matriculas.editar', only: ['update']),
            new Middleware('permission:matriculas.anular', only: ['anular']),
            new Middleware('permission:matriculas.eliminar', only: ['destroy']),
            new Middleware('permission:matriculas.restaurar', only: ['restaurar']),
            new Middleware('permission:matriculas.exportar', only: ['exportar']),
            // Como en el Blade: entra cualquiera de los tres; cada contexto exige después el suyo.
            new Middleware('permission:matriculas.ver|matriculas.crear|matriculas.editar', only: ['opcionesEstudiantes', 'opcionesCursos']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$query, $filtros] = $this->consultaFiltrada($request);
        $orden = Listado::ordenar($query, $request, self::ORDENABLES, '-fecha');

        return Listado::responder(
            Listado::paginar($query, $request),
            fn (Matricula $matricula): array => self::resumen($matricula),
            $orden,
            $filtros,
        );
    }

    public function show(Request $request, int $matricula): JsonResponse
    {
        $modelo = Matricula::withTrashed()->with(['estudiante', 'curso'])->findOrFail($matricula);

        return response()->json(['data' => $this->detalle($modelo, true)]);
    }

    public function store(StoreMatriculaRequest $request): JsonResponse
    {
        $matricula = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return response()->json([
            'message' => 'La matrícula se registró correctamente.',
            'data' => $this->detalle($matricula->refresh()->load(['estudiante', 'curso']), false),
        ], 201);
    }

    public function update(UpdateMatriculaRequest $request, Matricula $matricula): JsonResponse
    {
        $actualizada = $this->gestion->actualizar(
            $matricula,
            $request->validated(),
            $request->user(),
            AuditoriaAcademica::actorDe($request),
        );

        return response()->json([
            'message' => 'La matrícula se actualizó correctamente.',
            'data' => $this->detalle($actualizada->refresh()->load(['estudiante', 'curso']), false),
        ]);
    }

    public function anular(Request $request, Matricula $matricula): JsonResponse
    {
        $this->gestion->anular($matricula, AuditoriaAcademica::actorDe($request));

        return response()->json(['message' => 'La matrícula se anuló correctamente.']);
    }

    public function destroy(Request $request, Matricula $matricula): JsonResponse
    {
        $this->gestion->eliminar($matricula, AuditoriaAcademica::actorDe($request));

        return response()->json(['message' => 'La matrícula se eliminó correctamente.']);
    }

    public function restaurar(Request $request, int $matricula): JsonResponse
    {
        $this->gestion->restaurar($matricula, AuditoriaAcademica::actorDe($request));

        return response()->json(['message' => 'La matrícula se restauró correctamente.']);
    }

    /** Opciones del selector de estudiantes: `q` y `contexto` (`crear` o `editar`). */
    public function opcionesEstudiantes(Request $request): JsonResponse
    {
        $contexto = $this->contextoDeOpciones($request, [OpcionesMatricula::CREAR, OpcionesMatricula::EDITAR]);

        return response()->json(['data' => OpcionesMatricula::estudiantes($contexto, ListFilter::text($request, 'q'))]);
    }

    /** Opciones del selector de cursos: `q` y `contexto` (`crear`, `editar` o `filtro`). */
    public function opcionesCursos(Request $request): JsonResponse
    {
        $contexto = $this->contextoDeOpciones($request, OpcionesMatricula::CONTEXTOS);

        return response()->json(['data' => OpcionesMatricula::cursos($contexto, ListFilter::text($request, 'q'))]);
    }

    /**
     * Exportación CSV con los mismos filtros del listado, columnas, protección contra fórmulas (`csvSafe`) y
     * auditoría (`matriculas_csv_exported`, sin el texto buscado) que la exportación del Blade.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);
        [$query, $filtros] = $this->consultaFiltrada($request);
        $matriculas = $query->orderBy('id');
        $nombreArchivo = 'matriculas_'.now()->format('Ymd_His').'.csv';

        AuditoriaAcademica::registrarExportacionMatriculas(
            $nombreArchivo,
            $filtros['estado'] !== '' ? $filtros['estado'] : null,
            $filtros['curso_id'],
            $filtros['eliminados'],
            $filtros['busqueda'] !== '',
            $actor,
        );

        return response()->streamDownload(function () use ($matriculas): void {
            $salida = fopen('php://output', 'wb');
            if (! is_resource($salida)) {
                throw new RuntimeException('No se pudo crear el archivo CSV de matrículas.');
            }

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['ID', 'DNI', 'Estudiante', 'Curso', 'Código Curso', 'Fecha', 'Estado', 'Monto']);

            $matriculas->chunk(500, function ($lote) use ($salida): void {
                foreach ($lote as $matricula) {
                    fputcsv($salida, [
                        $matricula->id,
                        SensitiveData::csvSafe($matricula->estudiante?->dni),
                        SensitiveData::csvSafe(trim(($matricula->estudiante->nombres ?? '').' '.($matricula->estudiante->apellidos ?? ''))),
                        SensitiveData::csvSafe($matricula->curso?->nombre),
                        SensitiveData::csvSafe($matricula->curso?->codigo),
                        $matricula->fecha_matricula->format('Y-m-d'),
                        match ($matricula->estado) {
                            'pendiente' => 'Pendiente',
                            'pagada' => 'Pagada',
                            'anulada' => 'Anulada',
                            default => $matricula->estado,
                        },
                        $matricula->monto_pagado === null
                            ? ''
                            : number_format((float) $matricula->monto_pagado, 2, '.', ''),
                    ]);
                }
            });

            fclose($salida);
        }, $nombreArchivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Mismos filtros que el listado y la exportación del Blade.
     *
     * @return array{0: Builder<Matricula>, 1: array{busqueda: string, estado: string, curso_id: int|null, eliminados: bool}}
     */
    private function consultaFiltrada(Request $request): array
    {
        $query = Matricula::query()->with([
            'estudiante:id,dni,nombres,apellidos,deleted_at',
            'curso:id,codigo,nombre,deleted_at',
        ]);

        $cursoId = $request->input('curso_id');
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', MatriculaBlade::ESTADOS_VALIDOS),
            'curso_id' => is_string($cursoId) && ctype_digit($cursoId) && strlen($cursoId) <= 18 ? (int) $cursoId : null,
            'eliminados' => $request->boolean('eliminados'),
        ];

        if ($filtros['eliminados']) {
            $query->onlyTrashed();
        }
        if ($filtros['estado'] !== '') {
            $query->where('estado', $filtros['estado']);
        }
        if ($filtros['curso_id'] !== null) {
            $query->where('curso_id', $filtros['curso_id']);
        }
        if ($filtros['busqueda'] !== '') {
            $busqueda = $filtros['busqueda'];
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->whereHas('estudiante', function (Builder $estudiante) use ($busqueda): void {
                    BusquedaLike::contiene($estudiante, ['dni', 'nombres', 'apellidos'], $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $busqueda);
                });
            });
        }

        return [$query, $filtros];
    }

    /**
     * @param  list<string>  $permitidos
     */
    private function contextoDeOpciones(Request $request, array $permitidos): string
    {
        $contexto = $request->query('contexto');
        abort_unless(is_string($contexto) && in_array($contexto, $permitidos, true), 422, 'Contexto no válido.');

        $permiso = match ($contexto) {
            OpcionesMatricula::CREAR => 'matriculas.crear',
            OpcionesMatricula::EDITAR => 'matriculas.editar',
            default => 'matriculas.ver',
        };
        abort_unless($request->user()?->can($permiso) === true, 403);

        return $contexto;
    }

    /** @return array<string, mixed> */
    private static function resumen(Matricula $matricula): array
    {
        $estudiante = $matricula->estudiante;
        $curso = $matricula->curso;

        return [
            'id' => $matricula->id,
            'estudiante' => $estudiante === null ? null : [
                'id' => $estudiante->id,
                'dni' => $estudiante->dni,
                'nombre' => trim($estudiante->nombres.' '.$estudiante->apellidos),
                'eliminado' => $estudiante->trashed(),
            ],
            'curso' => $curso === null ? null : [
                'id' => $curso->id,
                'codigo' => $curso->codigo,
                'nombre' => $curso->nombre,
                'eliminado' => $curso->trashed(),
            ],
            'estado' => $matricula->estado,
            'fecha' => $matricula->fecha_matricula->toDateString(),
            'monto_pagado' => $matricula->monto_pagado,
            'eliminado' => $matricula->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(Matricula $matricula, bool $conHistorial): array
    {
        $datos = [
            ...self::resumen($matricula),
            'observaciones' => $matricula->observaciones,
            'curso_cupos_disponibles' => $matricula->curso?->cupos_disponibles,
            'creado_en' => $matricula->created_at?->toIso8601String(),
            'actualizado_en' => $matricula->updated_at?->toIso8601String(),
        ];

        if ($conHistorial) {
            $datos['historial'] = $this->historial($matricula);
        }

        return $datos;
    }

    /**
     * Historial de la matrícula a partir de su auditoría. Minimizado para quien ve matrículas (no requiere
     * `auditoria.ver`): acción, fecha, autor, efecto sobre el cupo, nombres de los campos cambiados y la
     * transición de estado. Nunca IP, montos anteriores ni observaciones.
     *
     * @return list<array<string, mixed>>
     */
    private function historial(Matricula $matricula): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where('model_type', Matricula::class)
            ->where('model_id', $matricula->id)
            ->whereIn('action', self::ACCIONES_HISTORIAL)
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (AuditLog $registro): array {
                $cambios = $registro->redactedChanges();
                $antes = is_array($cambios['antes'] ?? null) ? $cambios['antes'] : [];
                $despues = is_array($cambios['despues'] ?? null) ? $cambios['despues'] : [];
                $valores = is_array($cambios['valores'] ?? null) ? $cambios['valores'] : [];
                $campos = is_array($cambios['campos'] ?? null) ? array_values(array_filter($cambios['campos'], 'is_string')) : [];

                return [
                    'id' => $registro->id,
                    'accion' => $registro->action,
                    'fecha' => $registro->created_at->toIso8601String(),
                    'usuario' => $registro->user?->name,
                    'efecto_cupo' => is_string($cambios['efecto_cupo'] ?? null) ? $cambios['efecto_cupo'] : null,
                    'campos' => $campos,
                    'estado_anterior' => is_string($antes['estado'] ?? null) ? $antes['estado'] : null,
                    'estado_nuevo' => match (true) {
                        is_string($despues['estado'] ?? null) => $despues['estado'],
                        is_string($valores['estado'] ?? null) => $valores['estado'],
                        is_string($cambios['estado'] ?? null) => $cambios['estado'],
                        default => null,
                    },
                ];
            })
            ->all();
    }
}
