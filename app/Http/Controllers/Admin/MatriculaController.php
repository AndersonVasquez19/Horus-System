<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\GestionMatriculas;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\OpcionesMatricula;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatriculaController extends Controller implements HasMiddleware
{
    public const ESTADOS_VALIDOS = ['pendiente', 'pagada', 'anulada'];

    public function __construct(private readonly GestionMatriculas $gestion) {}

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:matriculas.ver', only: ['index', 'show']),
            new Middleware('permission:matriculas.crear', only: ['create', 'store']),
            new Middleware('permission:matriculas.editar', only: ['edit', 'update']),
            new Middleware('permission:matriculas.anular', only: ['anular']),
            new Middleware('permission:matriculas.eliminar', only: ['destroy']),
            new Middleware('permission:matriculas.restaurar', only: ['restaurar']),
            new Middleware('permission:matriculas.exportar', only: ['exportarCsv']),
            // Opciones de los selectores: cualquiera de los tres permisos entra; cada contexto exige el suyo (ver `opciones()`).
            new Middleware('permission:matriculas.ver|matriculas.crear|matriculas.editar', only: ['opcionesEstudiantes', 'opcionesCursos']),
        ];
    }

    public function index(Request $request): View
    {
        $matriculas = $this->filteredQuery($request)
            ->latest('fecha_matricula')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        // Saneado: la vista lo compara con (string); null mantiene el indicador "sin filtro".
        $cursoId = ListFilter::text($request, 'curso_id');

        return view('admin.matriculas.index', [
            'matriculas' => $matriculas,
            // El filtro de curso busca en el servidor (selector asíncrono): solo se resuelve el curso elegido.
            'cursoFiltro' => OpcionesMatricula::curso($cursoId, OpcionesMatricula::FILTRO),
            'estado' => ListFilter::in($request, 'estado', self::ESTADOS_VALIDOS),
            'cursoId' => $cursoId !== '' ? $cursoId : null,
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'eliminados' => $request->boolean('eliminados'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.matriculas.create', [
            // Los selectores buscan en el servidor (opcionesEstudiantes/opcionesCursos); solo se preselecciona, si
            // procede, un estudiante o curso elegible. No altera datos ni validaciones.
            'hayEstudiantes' => Estudiante::query()->where('estado', 'activo')->exists(),
            'hayCursos' => Curso::query()->where('estado', 'activo')->where('cupos_disponibles', '>', 0)->exists(),
            'estudianteSeleccionado' => ListFilter::text($request, 'estudiante_id'),
            'cursoSeleccionado' => ListFilter::text($request, 'curso_id'),
        ]);
    }

    public function store(StoreMatriculaRequest $request): RedirectResponse
    {
        $matricula = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.matriculas.show', $matricula)
            ->with('success', 'La matrícula se registró correctamente.');
    }

    public function show(Matricula $matricula): View
    {
        $matricula->load(['estudiante', 'curso']);

        return view('admin.matriculas.show', compact('matricula'));
    }

    public function edit(Matricula $matricula): View
    {
        $matricula->load(['estudiante', 'curso']);

        return view('admin.matriculas.edit', [
            'matricula' => $matricula,
        ]);
    }

    /** Opciones del selector de estudiantes (JSON): `q` y `contexto` (`crear` o `editar`). */
    public function opcionesEstudiantes(Request $request): JsonResponse
    {
        $contexto = $this->contextoDeOpciones($request, [OpcionesMatricula::CREAR, OpcionesMatricula::EDITAR]);

        return response()->json([
            'opciones' => OpcionesMatricula::estudiantes($contexto, ListFilter::text($request, 'q')),
        ]);
    }

    /** Opciones del selector de cursos (JSON): `q` y `contexto` (`crear`, `editar` o `filtro`). */
    public function opcionesCursos(Request $request): JsonResponse
    {
        $contexto = $this->contextoDeOpciones($request, OpcionesMatricula::CONTEXTOS);

        return response()->json([
            'opciones' => OpcionesMatricula::cursos($contexto, ListFilter::text($request, 'q')),
        ]);
    }

    public function update(UpdateMatriculaRequest $request, Matricula $matricula): RedirectResponse
    {
        $matricula = $this->gestion->actualizar(
            $matricula,
            $request->validated(),
            $request->user(),
            AuditoriaAcademica::actorDe($request),
        );

        return redirect()
            ->route('admin.matriculas.show', $matricula)
            ->with('success', 'La matrícula se actualizó correctamente.');
    }

    public function destroy(Request $request, Matricula $matricula): RedirectResponse
    {
        $this->gestion->eliminar($matricula, AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se eliminó correctamente.');
    }

    public function anular(Request $request, Matricula $matricula): RedirectResponse
    {
        $this->gestion->anular($matricula, AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se anuló correctamente.');
    }

    public function restaurar(Request $request, string $matricula): RedirectResponse
    {
        $this->gestion->restaurar($matricula, AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se restauró correctamente.');
    }

    public function exportarCsv(Request $request): StreamedResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);
        $matriculas = $this->filteredQuery($request)->orderBy('id');
        $nombreArchivo = 'matriculas_'.now()->format('Ymd_His').'.csv';

        // Lista cerrada (Sprint 21.2, Fase 4): los filtros efectivos son los mismos de filteredQuery(); el texto
        // buscado (DNI, nombres…) no se audita, solo si se aplicó una búsqueda.
        $estado = ListFilter::in($request, 'estado', self::ESTADOS_VALIDOS);
        $cursoId = $request->input('curso_id');
        AuditoriaAcademica::registrarExportacionMatriculas(
            $nombreArchivo,
            $estado !== '' ? $estado : null,
            is_string($cursoId) && ctype_digit($cursoId) ? (int) $cursoId : null,
            $request->boolean('eliminados'),
            ListFilter::text($request, 'busqueda') !== '',
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
        ]);
    }

    /**
     * Valida el contexto del selector y exige el permiso de esa pantalla: crear → `matriculas.crear`, editar →
     * `matriculas.editar`, filtro → `matriculas.ver`.
     *
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

    /** @return Builder<Matricula> */
    private function filteredQuery(Request $request): Builder
    {
        $query = Matricula::query()->with(['estudiante', 'curso']);

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $estado = ListFilter::in($request, 'estado', self::ESTADOS_VALIDOS);
        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $cursoId = $request->input('curso_id');
        if (is_string($cursoId) && ctype_digit($cursoId)) {
            $query->where('curso_id', (int) $cursoId);
        }

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->whereHas('estudiante', function (Builder $estudiante) use ($busqueda): void {
                    BusquedaLike::contiene($estudiante, ['dni', 'nombres', 'apellidos'], $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $busqueda);
                });
            });
        }

        return $query;
    }
}
