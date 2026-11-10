<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstudianteRequest;
use App\Http\Requests\UpdateEstudianteRequest;
use App\Models\Estudiante;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EstudianteController extends Controller implements HasMiddleware
{
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

        $estado = $request->input('estado');
        if (in_array($estado, ['activo', 'inactivo'], true)) {
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
        $actor = $this->actorId($request);

        // La auditoría es la última escritura de la transacción: si falla, el alta se revierte (fail-closed).
        DB::transaction(function () use ($request, $actor): void {
            $estudiante = Estudiante::create($request->validated());

            AuditoriaAcademica::registrarCreacion('estudiante_creado', $estudiante, AuditoriaAcademica::ESTUDIANTE, $actor);
        });

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
        $actor = $this->actorId($request);

        DB::transaction(function () use ($request, $estudiante, $actor): void {
            // Se relee la fila bajo bloqueo para que «antes» sea el valor real y no el del enlace de ruta.
            $bloqueado = Estudiante::query()->lockForUpdate()->findOrFail($estudiante->getKey());
            $bloqueado->fill($request->validated());
            $diferencias = AuditoriaAcademica::diferencias($bloqueado, AuditoriaAcademica::ESTUDIANTE);
            $bloqueado->save();

            AuditoriaAcademica::registrarActualizacion('estudiante_actualizado', $bloqueado, $diferencias, $actor);
        });

        return redirect()
            ->route('admin.estudiantes.show', $estudiante)
            ->with('success', 'Los datos del estudiante se actualizaron correctamente.');
    }

    public function destroy(Request $request, Estudiante $estudiante): RedirectResponse|JsonResponse
    {
        $actor = $this->actorId($request);
        $vigentes = 0;

        DB::transaction(function () use ($estudiante, $actor, &$vigentes): void {
            // Sin SoftDeletes::withTrashed(): si otra petición ya lo eliminó, no hay operación efectiva ni auditoría.
            $bloqueado = Estudiante::query()->lockForUpdate()->find($estudiante->getKey());

            if ($bloqueado === null) {
                return;
            }

            // Con matrículas vigentes (pendiente/pagada) el estudiante seguiría ocupando cupos de sus cursos:
            // hay que anularlas antes. Se evalúa bajo el bloqueo del estudiante.
            $vigentes = $bloqueado->matriculas()->whereIn('estado', ['pendiente', 'pagada'])->count();
            if ($vigentes > 0) {
                return;
            }

            $bloqueado->delete();

            AuditoriaAcademica::registrarOperacion('estudiante_eliminado', $bloqueado, 'eliminacion_logica', $actor);
        });

        if ($vigentes > 0) {
            $mensaje = 'No se puede desactivar al estudiante: tiene '.$vigentes.' matrícula(s) vigente(s). Anúlalas primero para liberar sus cupos.';

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
        $actor = $this->actorId($request);

        DB::transaction(function () use ($estudiante, $actor): void {
            $bloqueado = Estudiante::withTrashed()->lockForUpdate()->findOrFail($estudiante);

            // Restaurar un estudiante no eliminado no es una operación efectiva: no se audita (evita duplicados).
            if (! $bloqueado->trashed()) {
                return;
            }

            $bloqueado->restore();

            AuditoriaAcademica::registrarOperacion('estudiante_restaurado', $bloqueado, 'restauracion', $actor);
        });

        return redirect()
            ->route('admin.estudiantes.index', ['eliminados' => 1])
            ->with('success', 'El estudiante se restauró correctamente.');
    }

    /** Identificador explícito del usuario autenticado; la auditoría no depende del fallback de auth(). */
    private function actorId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 403);

        return (int) $id;
    }
}
