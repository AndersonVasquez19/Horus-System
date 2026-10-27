<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEstudianteRequest;
use App\Http\Requests\UpdateEstudianteRequest;
use App\Models\Estudiante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EstudianteController extends Controller
{
    public function index(Request $request): View
    {
        $query = Estudiante::query();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $busqueda = trim((string) $request->input('busqueda'));
        if ($busqueda !== '') {
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->where('dni', 'like', "%{$busqueda}%")
                    ->orWhere('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%");
            });
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
        Estudiante::create($request->validated());

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
        $estudiante->update($request->validated());

        return redirect()
            ->route('admin.estudiantes.show', $estudiante)
            ->with('success', 'Los datos del estudiante se actualizaron correctamente.');
    }

    public function destroy(Estudiante $estudiante): RedirectResponse
    {
        $estudiante->delete();

        return redirect()
            ->route('admin.estudiantes.index')
            ->with('success', 'El estudiante se desactivó correctamente.');
    }

    public function restaurar(string $estudiante): RedirectResponse
    {
        $estudiante = Estudiante::withTrashed()->findOrFail($estudiante);
        $estudiante->restore();

        return redirect()
            ->route('admin.estudiantes.index', ['eliminados' => 1])
            ->with('success', 'El estudiante se restauró correctamente.');
    }
}
