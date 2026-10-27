<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCursoRequest;
use App\Http\Requests\UpdateCursoRequest;
use App\Models\Curso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CursoController extends Controller
{
    public function index(Request $request): View
    {
        $query = Curso::query();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }

        $busqueda = trim((string) $request->input('busqueda'));
        if ($busqueda !== '') {
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->where('codigo', 'like', "%{$busqueda}%")
                    ->orWhere('nombre', 'like', "%{$busqueda}%");
            });
        }

        $categoria = $request->input('categoria');
        if (in_array($categoria, ['tecnologia', 'educacion', 'salud', 'gestion', 'otros'], true)) {
            $query->where('categoria', $categoria);
        }

        $estado = $request->input('estado');
        if (in_array($estado, ['activo', 'inactivo', 'archivado'], true)) {
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
        $datos = $request->validated();
        $datos['cupos_disponibles'] = $datos['cupos_maximos'];
        $curso = Curso::create($datos);

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
        $datos = $request->validated();

        DB::transaction(function () use ($curso, &$datos): void {
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
            $curso->update($datos);
        });

        return redirect()
            ->route('admin.cursos.show', $curso)
            ->with('success', 'Los datos del curso se actualizaron correctamente.');
    }

    public function destroy(Curso $curso): RedirectResponse
    {
        $curso->delete();

        return redirect()
            ->route('admin.cursos.index')
            ->with('success', 'El curso se archivó correctamente.');
    }

    public function restaurar(string $curso): RedirectResponse
    {
        $curso = Curso::withTrashed()->findOrFail($curso);
        $curso->restore();

        return redirect()
            ->route('admin.cursos.index', ['eliminados' => 1])
            ->with('success', 'El curso se restauró correctamente.');
    }
}
