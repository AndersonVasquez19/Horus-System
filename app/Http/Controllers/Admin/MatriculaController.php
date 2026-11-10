<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
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
            'estado' => $request->input('estado'),
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
        $datos = $this->conIdentificadoresEnteros($request->validated());
        $actor = AuditoriaAcademica::actorDe($request);

        $matricula = DB::transaction(function () use ($datos, $actor): Matricula {
            $curso = Curso::withTrashed()
                ->lockForUpdate()
                ->findOrFail($datos['curso_id']);

            // La validación HTTP puede haber quedado obsoleta antes de adquirir el lock.
            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso seleccionado no está disponible.',
                ]);
            }

            $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($datos['estudiante_id']);
            if ($estudiante->trashed() || $estudiante->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'estudiante_id' => 'El estudiante seleccionado no está activo.',
                ]);
            }

            if ($datos['estado'] !== 'anulada') {
                $this->assertNoActiveDuplicate($datos['estudiante_id'], $curso->id);

                if ($curso->cupos_disponibles <= 0) {
                    throw ValidationException::withMessages([
                        'curso_id' => 'El curso no tiene cupos disponibles.',
                    ]);
                }

                $curso->decrement('cupos_disponibles');
            }

            $matricula = Matricula::create($datos);

            // Última escritura de la transacción: si la auditoría falla se revierten matrícula, cupo e intención de aviso.
            AuditoriaAcademica::registrarCreacion(
                'matricula_creada',
                $matricula,
                AuditoriaAcademica::MATRICULA,
                $actor,
                AuditoriaAcademica::efectoCupo(null, null, $datos['estado'], (int) $curso->id),
            );

            return $matricula;
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
        $datos = $this->conIdentificadoresEnteros($request->validated());
        $actor = AuditoriaAcademica::actorDe($request);

        $matricula = DB::transaction(function () use ($matricula, $datos, $request, $actor): Matricula {
            $matricula = $this->lockMatricula($matricula->id, (int) $datos['curso_id'], (int) $datos['estudiante_id']);

            $estadoAnterior = (string) $matricula->estado;
            $anteriorActiva = $matricula->estado !== 'anulada';
            $nuevaActiva = $datos['estado'] !== 'anulada';
            if ($anteriorActiva && ! $nuevaActiva) {
                abort_unless($request->user()->can('matriculas.anular'), 403);
            }
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

            if ($cursoNuevo === null || $cursoNuevo->trashed()) {
                throw ValidationException::withMessages([
                    'curso_id' => 'El curso seleccionado no está disponible.',
                ]);
            }

            $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($datos['estudiante_id']);
            if ($estudiante->trashed()) {
                throw ValidationException::withMessages([
                    'estudiante_id' => 'El estudiante seleccionado no está disponible.',
                ]);
            }

            if ($nuevaActiva) {
                $this->assertNoActiveDuplicate(
                    (int) $datos['estudiante_id'],
                    $cursoNuevoId,
                    $matricula->id,
                );
            }

            $requiereCupoNuevo = $nuevaActiva
                && (! $anteriorActiva || $cursoAnteriorId !== $cursoNuevoId);

            if ($requiereCupoNuevo) {
                $this->assertEligibleForOccupation($estudiante, $cursoNuevo);
            }

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

            // Las diferencias se calculan sobre la fila bloqueada, antes de guardar. Anular mediante PUT produce
            // un único evento «matricula_anulada» con todas las diferencias permitidas, nunca dos eventos.
            $matricula->fill($datos);
            $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
            $matricula->save();

            AuditoriaAcademica::registrarActualizacion(
                $anteriorActiva && ! $nuevaActiva ? 'matricula_anulada' : 'matricula_actualizada',
                $matricula,
                $diferencias,
                $actor,
                AuditoriaAcademica::efectoCupo($estadoAnterior, $cursoAnteriorId, $datos['estado'], $cursoNuevoId),
            );

            return $matricula;
        });

        return redirect()
            ->route('admin.matriculas.show', $matricula)
            ->with('success', 'La matrícula se actualizó correctamente.');
    }

    public function destroy(Request $request, Matricula $matricula): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);

        DB::transaction(function () use ($matricula, $actor): void {
            $matricula = $this->lockMatricula($matricula->id);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');
            }

            $matricula->delete();

            // Última escritura de la transacción. El efecto y el estado salen de la fila bloqueada: eliminar una
            // vigente libera su cupo; eliminar una anulada no mueve ninguno.
            AuditoriaAcademica::registrarOperacionMatricula(
                'matricula_eliminada',
                $matricula,
                AuditoriaAcademica::OPERACION_ELIMINACION,
                AuditoriaAcademica::efectoCupo((string) $matricula->estado, (int) $matricula->curso_id, null, null),
                $actor,
            );
        });

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se eliminó correctamente.');
    }

    public function anular(Request $request, Matricula $matricula): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);

        DB::transaction(function () use ($matricula, $actor): void {
            $matricula = $this->lockMatricula($matricula->id);

            // Repetir la anulación no cambia nada, no devuelve cupo otra vez y no genera otro evento.
            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);
                $curso->increment('cupos_disponibles');

                $estadoAnterior = (string) $matricula->estado;
                $matricula->fill(['estado' => 'anulada']);
                $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
                $matricula->save();

                AuditoriaAcademica::registrarActualizacion(
                    'matricula_anulada',
                    $matricula,
                    $diferencias,
                    $actor,
                    AuditoriaAcademica::efectoCupo($estadoAnterior, (int) $curso->id, 'anulada', (int) $curso->id),
                );
            }
        });

        return redirect()
            ->route('admin.matriculas.index')
            ->with('success', 'La matrícula se anuló correctamente.');
    }

    public function restaurar(Request $request, string $matricula): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);

        DB::transaction(function () use ($matricula, $actor): void {
            $matricula = $this->lockMatricula($matricula, withTrashed: true);

            abort_unless($matricula->trashed(), 404);

            if ($matricula->estado !== 'anulada') {
                $curso = Curso::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($matricula->curso_id);

                $estudiante = Estudiante::withTrashed()->lockForUpdate()->findOrFail($matricula->estudiante_id);
                $this->assertEligibleForOccupation($estudiante, $curso);

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

            // Última escritura de la transacción: restaurar una vigente vuelve a ocupar cupo; una anulada no.
            AuditoriaAcademica::registrarOperacionMatricula(
                'matricula_restaurada',
                $matricula,
                AuditoriaAcademica::OPERACION_RESTAURACION,
                AuditoriaAcademica::efectoCupo(null, (int) $matricula->curso_id, (string) $matricula->estado, (int) $matricula->curso_id),
                $actor,
            );
        });

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
        $estado = $request->input('estado');
        $cursoId = $request->input('curso_id');
        AuditoriaAcademica::registrarExportacionMatriculas(
            $nombreArchivo,
            in_array($estado, ['pendiente', 'pagada', 'anulada'], true) ? $estado : null,
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
     * `validated()` no convierte tipos: un formulario HTML envía los identificadores como cadena y los métodos
     * estrictos (`int $estudianteId`) los rechazarían. Se normalizan una sola vez, justo tras la validación.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function conIdentificadoresEnteros(array $datos): array
    {
        foreach (['estudiante_id', 'curso_id'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $datos[$campo] = (int) $datos[$campo];
            }
        }

        return $datos;
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
                    BusquedaLike::contiene($estudiante, ['dni', 'nombres', 'apellidos'], $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $busqueda);
                });
            });
        }

        return $query;
    }

    /**
     * Dentro de la transacción: cursos por id, estudiantes por id, matrícula.
     * La lectura inicial solo descubre las relaciones; la fila bloqueada decide.
     * Si cambiaron mientras esperábamos, se rechaza sin escribir ni reintentar.
     */
    private function lockMatricula(int|string $id, ?int $cursoDestino = null, ?int $estudianteDestino = null, bool $withTrashed = false): Matricula
    {
        $query = Matricula::query()->withTrashed($withTrashed);
        $snapshot = (clone $query)->findOrFail($id);

        Curso::withTrashed()
            ->whereIn('id', array_unique([$snapshot->curso_id, $cursoDestino ?? $snapshot->curso_id]))
            ->orderBy('id')->lockForUpdate()->get();
        Estudiante::withTrashed()
            ->whereIn('id', array_unique([$snapshot->estudiante_id, $estudianteDestino ?? $snapshot->estudiante_id]))
            ->orderBy('id')->lockForUpdate()->get();
        $matricula = $query->lockForUpdate()->findOrFail($id);

        if ($matricula->curso_id !== $snapshot->curso_id || $matricula->estudiante_id !== $snapshot->estudiante_id) {
            throw ValidationException::withMessages([
                'curso_id' => 'La matrícula cambió durante la operación. Recarga los datos antes de continuar.',
            ]);
        }

        return $matricula;
    }

    private function assertEligibleForOccupation(Estudiante $estudiante, Curso $curso): void
    {
        if ($estudiante->trashed() || $estudiante->estado !== 'activo') {
            throw ValidationException::withMessages([
                'estudiante_id' => 'El estudiante seleccionado no está activo.',
            ]);
        }
        if ($curso->trashed() || $curso->estado !== 'activo') {
            throw ValidationException::withMessages([
                'curso_id' => 'El curso seleccionado no está disponible.',
            ]);
        }
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
