<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursoRequest;
use App\Http\Requests\UpdateCursoRequest;
use App\Models\Curso;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CursoController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:cursos.ver', only: ['index', 'show']),
            new Middleware('permission:cursos.crear', only: ['create', 'store']),
            new Middleware('permission:cursos.editar', only: ['edit', 'update']),
            new Middleware('permission:cursos.eliminar', only: ['destroy']),
            new Middleware('permission:cursos.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Curso::query();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            BusquedaLike::contiene($query, ['codigo', 'nombre'], $busqueda);
        }

        $categoria = ListFilter::in($request, 'categoria', ['tecnologia', 'educacion', 'salud', 'gestion', 'otros']);
        if ($categoria !== '') {
            $query->where('categoria', $categoria);
        }

        $estado = ListFilter::in($request, 'estado', ['activo', 'inactivo', 'archivado']);
        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $cursos = $query
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('admin.cursos.index', [
            'cursos' => $cursos,
            'busqueda' => $busqueda,
            'categoria' => $categoria,
            'estado' => $estado,
            'eliminados' => $request->boolean('eliminados'),
        ]);
    }

    public function create(): View
    {
        return view('admin.cursos.create');
    }

    public function store(StoreCursoRequest $request): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);
        $datos = $request->validated();
        $datos['cupos_disponibles'] = $datos['cupos_maximos'];

        // La auditoría es la última escritura de la transacción: si falla, el alta se revierte (fail-closed).
        $curso = DB::transaction(function () use ($datos, $actor): Curso {
            $curso = Curso::create($datos);

            AuditoriaAcademica::registrarCreacion('curso_creado', $curso, AuditoriaAcademica::CURSO, $actor);

            return $curso;
        });

        return redirect()
            ->route('admin.cursos.show', $curso)
            ->with('success', 'El curso se registró correctamente.');
    }

    public function show(Curso $curso): View
    {
        $curso->load([
            'estudiantes' => fn ($query) => $query->orderBy('apellidos')->orderBy('nombres'),
        ]);

        return view('admin.cursos.show', compact('curso'));
    }

    public function edit(Curso $curso): View
    {
        $matriculasActivas = $curso->matriculas()
            ->where('estado', '!=', 'anulada')
            ->count();

        return view('admin.cursos.edit', compact('curso', 'matriculasActivas'));
    }

    public function update(UpdateCursoRequest $request, Curso $curso): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);
        $datos = $request->validated();

        DB::transaction(function () use ($curso, &$datos, $actor): void {
            $curso = Curso::query()->lockForUpdate()->findOrFail($curso->id);
            $matriculasActivas = $curso->matriculas()
                ->where('estado', '!=', 'anulada')
                ->count();

            if ($datos['cupos_maximos'] < $matriculasActivas) {
                throw ValidationException::withMessages([
                    'cupos_maximos' => "No puedes reducir los cupos por debajo de las {$matriculasActivas} matrículas activas.",
                ]);
            }

            $datos['cupos_disponibles'] = $datos['cupos_maximos'] - $matriculasActivas;
            $curso->fill($datos);
            $diferencias = AuditoriaAcademica::diferencias($curso, AuditoriaAcademica::CURSO);
            $curso->save();

            AuditoriaAcademica::registrarActualizacion('curso_actualizado', $curso, $diferencias, $actor);
        });

        return redirect()
            ->route('admin.cursos.show', $curso)
            ->with('success', 'Los datos del curso se actualizaron correctamente.');
    }

    public function destroy(Request $request, Curso $curso): RedirectResponse|JsonResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);
        $vigentes = 0;

        DB::transaction(function () use ($curso, $actor, &$vigentes): void {
            // Sin withTrashed(): si otra petición ya lo archivó, no hay operación efectiva ni auditoría.
            $bloqueado = Curso::query()->lockForUpdate()->find($curso->getKey());

            if ($bloqueado === null) {
                return;
            }

            // Con matrículas vigentes (pendiente/pagada) el curso conservaría cupos ocupados por alumnos que
            // siguen inscritos: hay que anularlas antes. Se evalúa bajo el bloqueo del curso.
            $vigentes = $bloqueado->matriculas()->whereIn('estado', ['pendiente', 'pagada'])->count();
            if ($vigentes > 0) {
                return;
            }

            $bloqueado->delete();

            AuditoriaAcademica::registrarOperacion('curso_archivado', $bloqueado, 'archivado_logico', $actor);
        });

        if ($vigentes > 0) {
            $mensaje = 'No se puede archivar el curso: tiene '.$vigentes.' matrícula(s) vigente(s). Anúlalas primero para liberar sus cupos.';

            return $request->expectsJson()
                ? response()->json(['message' => $mensaje], 422)
                : redirect()->route('admin.cursos.show', $curso)->with('error', $mensaje);
        }

        return redirect()
            ->route('admin.cursos.index')
            ->with('success', 'El curso se archivó correctamente.');
    }

    public function restaurar(Request $request, string $curso): RedirectResponse
    {
        $actor = AuditoriaAcademica::actorDe($request);

        DB::transaction(function () use ($curso, $actor): void {
            $bloqueado = Curso::withTrashed()->lockForUpdate()->findOrFail($curso);

            // Restaurar un curso no archivado no es una operación efectiva: no se audita (evita duplicados).
            if (! $bloqueado->trashed()) {
                return;
            }

            $bloqueado->restore();

            AuditoriaAcademica::registrarOperacion('curso_restaurado', $bloqueado, 'restauracion', $actor);
        });

        return redirect()
            ->route('admin.cursos.index', ['eliminados' => 1])
            ->with('success', 'El curso se restauró correctamente.');
    }
}
