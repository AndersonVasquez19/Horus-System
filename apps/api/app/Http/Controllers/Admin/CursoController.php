<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursoRequest;
use App\Http\Requests\UpdateCursoRequest;
use App\Models\Curso;
use App\Services\GestionCursos;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class CursoController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionCursos $gestion) {}

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
        $curso = $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

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
        $this->gestion->actualizar($curso, $request->validated(), AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.cursos.show', $curso)
            ->with('success', 'Los datos del curso se actualizaron correctamente.');
    }

    public function destroy(Request $request, Curso $curso): RedirectResponse|JsonResponse
    {
        $vigentes = $this->gestion->archivar($curso, AuditoriaAcademica::actorDe($request));

        if ($vigentes > 0) {
            $mensaje = GestionCursos::mensajeMatriculasVigentes($vigentes);

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
        $this->gestion->restaurar($curso, AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.cursos.index', ['eliminados' => 1])
            ->with('success', 'El curso se restauró correctamente.');
    }
}
