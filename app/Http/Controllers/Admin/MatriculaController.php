<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Support\ListFilter;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatriculaController extends Controller implements HasMiddleware
{
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
            'cursos' => Curso::withTrashed()->orderBy('nombre')->get(),
            'estado' => $request->input('estado'),
            'cursoId' => $cursoId !== '' ? $cursoId : null,
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'eliminados' => $request->boolean('eliminados'),
        ]);
    }

    public function create(): View
    {
        return view('admin.matriculas.create', [
            'estudiantes' => Estudiante::query()
                ->where('estado', 'activo')
                ->orderBy('apellidos')
                ->orderBy('nombres')
                ->get(),
            'cursos' => Curso::query()
                ->where('estado', 'activo')
                ->where('cupos_disponibles', '>', 0)
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function store(StoreMatriculaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $matricula = DB::transaction(function () use ($datos): Matricula {
            $curso = Curso::query()
                ->lockForUpdate()
                ->findOrFail($datos['curso_id']);

            if ($datos['estado'] !== 'anulada') {
                $this->assertNoActiveDuplicate($datos['estudiante_id'], $curso->id);

                if ($curso->cupos_disponibles <= 0) {
                    throw ValidationException::withMessages([
                        'curso_id' => 'El curso no tiene cupos disponibles.',
                    ]);
                }

                $curso->decrement('cupos_disponibles');
            }

            return Matricula::create($datos);
        });

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
            'estudiantes' => Estudiante::query()
                ->orderBy('apellidos')
                ->orderBy('nombres')
                ->get(),
            'cursos' => Curso::query()
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function update(UpdateMatriculaRequest $request, Matricula $matricula): RedirectResponse
    {
        $datos = $request->validated();

        $matricula = DB::transaction(function () use ($matricula, $datos): Matricula {
            $matricula = Matricula::query()
                ->lockForUpdate()
                ->findOrFail($matricula->id);

            $anteriorActiva = $matricula->estado !== 'anulada';
            $nuevaActiva = $datos['estado'] !== 'anulada';
            $cursoAnteriorId = (int) $matricula->curso_id;
            $cursoNuevoId = (int) $datos['curso_id'];
            $cursos = Curso::withTrashed()
                ->whereIn('id', array_unique([$cursoAnteriorId, $cursoNuevoId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $cursoAnterior = $cursos->get($cursoAnteriorId);
            $cursoNuevo = $cursos->get($cursoNuevoId);

            if ($nuevaActiva) {
                $this->assertNoActiveDuplicate(
                    (int) $datos['estudiante_id'],
                    $cursoNuevoId,
                    $matricula->id,
                );
            }

            $requiereCupoNuevo = $nuevaActiva
                && (! $anteriorActiva || $cursoAnteriorId !== $cursoNuevoId);

            if ($requiereCupoNuevo && $cursoNuevo->cupos_disponibles <= 0) {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso no tiene cupos disponibles.',
                ]);
            }

            if ($anteriorActiva && (! $nuevaActiva || $cursoAnteriorId !== $cursoNuevoId)) {
                $cursoAnterior->increment('cupos_disponibles');
            }

            if ($requiereCupoNuevo) {
                $cursoNuevo->decrement('cupos_disponibles');
            }

            $matricula->update($datos);

            return $matricula;
        });

        return redirect()
            ->route('admin.matriculas.show', $matricula)
            ->with('success', 'La matrícula se actualizó correctamente.');
    }

    public function destroy(Matricula $matricula): RedirectResponse
    {
        DB::transaction(function () use ($matricula): void {
            $matricula = Matricula::query()
                ->lockForUpdate()
                ->findOrFail($matricula->id);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');
            }

            $matricula->delete();
        });

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se eliminó correctamente.');
    }

    public function anular(Matricula $matricula): RedirectResponse
    {
        DB::transaction(function () use ($matricula): void {
            $matricula = Matricula::query()
                ->lockForUpdate()
                ->findOrFail($matricula->id);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');
                $matricula->update(['estado' => 'anulada']);
            }
        });

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se anuló correctamente.');
    }

    public function restaurar(string $matricula): RedirectResponse
    {
        DB::transaction(function () use ($matricula): void {
            $matricula = Matricula::withTrashed()
                ->lockForUpdate()
                ->findOrFail($matricula);

            abort_unless($matricula->trashed(), 404);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);

                $this->assertNoActiveDuplicate(
                    (int) $matricula->estudiante_id,
                    (int) $curso->id,
                );

                if ($curso->cupos_disponibles <= 0) {
                    throw ValidationException::withMessages([
                        'curso_id' => 'El curso no tiene cupos disponibles para restaurar esta matrícula.',
                    ]);
                }

                $curso->decrement('cupos_disponibles');
            }

            $matricula->restore();
        });

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se restauró correctamente.');
    }

    public function exportarCsv(Request $request): StreamedResponse
    {
        $matriculas = $this->filteredQuery($request)->orderBy('id');
        $nombreArchivo = 'matriculas_'.now()->format('Ymd_His').'.csv';

        AuditLog::record('matriculas_csv_exported', Matricula::class, null, [
            'filename' => $nombreArchivo,
            'filters' => $request->only(['estado', 'curso_id', 'busqueda', 'eliminados']),
        ]);

        return response()->streamDownload(function () use ($matriculas): void {
            $salida = fopen('php://output', 'wb');
            if (! is_resource($salida)) {
                throw new RuntimeException('No se pudo crear el archivo CSV de matrículas.');
            }

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['ID', 'DNI', 'Estudiante', 'Curso', 'Código Curso', 'Fecha', 'Estado', 'Monto']);

            $matriculas->chunk(500, function (Collection $lote) use ($salida): void {
                foreach ($lote as $matricula) {
                    fputcsv($salida, [
                        $matricula->id,
                        SensitiveData::csvSafe($matricula->estudiante?->dni),
                        SensitiveData::csvSafe(trim(($matricula->estudiante?->nombres ?? '').' '.($matricula->estudiante?->apellidos ?? ''))),
                        SensitiveData::csvSafe($matricula->curso?->nombre),
                        SensitiveData::csvSafe($matricula->curso?->codigo),
                        $matricula->fecha_matricula?->format('Y-m-d') ?? '',
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

    private function filteredQuery(Request $request): Builder
    {
        $query = Matricula::query()->with(['estudiante', 'curso']);

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $estado = $request->input('estado');
        if (in_array($estado, ['pendiente', 'pagada', 'anulada'], true)) {
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
                    $estudiante->whereLike('dni', "%{$busqueda}%")
                        ->orWhereLike('nombres', "%{$busqueda}%")
                        ->orWhereLike('apellidos', "%{$busqueda}%");
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    $curso->whereLike('codigo', "%{$busqueda}%")
                        ->orWhereLike('nombre', "%{$busqueda}%");
                });
            });
        }

        return $query;
    }

    private function assertNoActiveDuplicate(int $estudianteId, int $cursoId, ?int $excluirId = null): void
    {
        $query = Matricula::query()
            ->where('estudiante_id', $estudianteId)
            ->where('curso_id', $cursoId)
            ->where('estado', '!=', 'anulada');

        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'estudiante_id' => 'El estudiante ya está matriculado en este curso.',
            ]);
        }
    }
}
