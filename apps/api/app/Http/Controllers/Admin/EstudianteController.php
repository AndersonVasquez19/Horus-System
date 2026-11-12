<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstudianteRequest;
use App\Http\Requests\UpdateEstudianteRequest;
use App\Models\Estudiante;
use App\Services\GestionEstudiantes;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class EstudianteController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionEstudiantes $gestion) {}

    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:estudiantes.ver', only: ['index', 'show']),
            new Middleware('permission:estudiantes.crear', only: ['create', 'store']),
            new Middleware('permission:estudiantes.editar', only: ['edit', 'update']),
            new Middleware('permission:estudiantes.eliminar', only: ['destroy']),
            new Middleware('permission:estudiantes.restaurar', only: ['restaurar']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Estudiante::query();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            BusquedaLike::contiene($query, ['dni', 'nombres', 'apellidos'], $busqueda);
        }

        $estado = ListFilter::in($request, 'estado', ['activo', 'inactivo']);
        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $estudiantes = $query
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate(15)
            ->withQueryString();

        return view('admin.estudiantes.index', [
            'estudiantes' => $estudiantes,
            'busqueda' => $busqueda,
            'estado' => $estado,
            'eliminados' => $request->boolean('eliminados'),
        ]);
    }

    public function create(): View
    {
        return view('admin.estudiantes.create');
    }

    public function store(StoreEstudianteRequest $request): RedirectResponse
    {
        $this->gestion->crear($request->validated(), AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.estudiantes.index')
            ->with('success', 'El estudiante se registró correctamente.');
    }

    public function show(Estudiante $estudiante): View
    {
        $estudiante->load([
            'matriculas' => fn ($query) => $query->with('curso')->latest('fecha_matricula'),
        ]);

        return view('admin.estudiantes.show', compact('estudiante'));
    }

    public function edit(Estudiante $estudiante): View
    {
        return view('admin.estudiantes.edit', compact('estudiante'));
    }

    public function update(UpdateEstudianteRequest $request, Estudiante $estudiante): RedirectResponse
    {
        $this->gestion->actualizar($estudiante, $request->validated(), AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.estudiantes.show', $estudiante)
            ->with('success', 'Los datos del estudiante se actualizaron correctamente.');
    }

    public function destroy(Request $request, Estudiante $estudiante): RedirectResponse|JsonResponse
    {
        $vigentes = $this->gestion->eliminar($estudiante, AuditoriaAcademica::actorDe($request));

        if ($vigentes > 0) {
            $mensaje = GestionEstudiantes::mensajeMatriculasVigentes($vigentes);

            return $request->expectsJson()
                ? response()->json(['message' => $mensaje], 422)
                : redirect()->route('admin.estudiantes.show', $estudiante)->with('error', $mensaje);
        }

        return redirect()
            ->route('admin.estudiantes.index')
            ->with('success', 'El estudiante se desactivó correctamente.');
    }

    public function restaurar(Request $request, string $estudiante): RedirectResponse
    {
        $this->gestion->restaurar($estudiante, AuditoriaAcademica::actorDe($request));

        return redirect()
            ->route('admin.estudiantes.index', ['eliminados' => 1])
            ->with('success', 'El estudiante se restauró correctamente.');
    }
}
