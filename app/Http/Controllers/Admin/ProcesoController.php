<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProcesoInterno;
use App\Models\User;
use App\Support\ListFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class ProcesoController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:procesos.ver', only: ['index', 'show']),
            new Middleware('permission:procesos.gestionar', only: ['create', 'store', 'edit', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $query = ProcesoInterno::query()->with(['responsable', 'creador']);

        $estado = ListFilter::text($request, 'estado');
        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        $busqueda = ListFilter::text($request, 'busqueda');
        if ($busqueda !== '') {
            $query->where(function ($q) use ($busqueda): void {
                $q->whereLike('titulo', "%{$busqueda}%")
                    ->orWhereLike('descripcion', "%{$busqueda}%")
                    ->orWhereLike('observaciones', "%{$busqueda}%");
            });
        }

        $procesos = $query
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 1 WHEN 'en_proceso' THEN 2 WHEN 'completado' THEN 3 WHEN 'cancelado' THEN 4 ELSE 5 END")
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.procesos.index', [
            'procesos' => $procesos,
            'estado' => $estado,
            'busqueda' => $busqueda,
            'responsables' => User::orderBy('name')->get(),
        ]);
    }

    public function show(ProcesoInterno $proceso): RedirectResponse
    {
        return redirect()->route('admin.procesos.edit', $proceso);
    }

    public function create(): View
    {
        return view('admin.procesos.create', [
            'responsables' => User::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'responsable_id' => ['nullable', 'exists:users,id'],
            'estado' => ['required', 'in:pendiente,en_proceso,completado,cancelado'],
            'prioridad' => ['required', 'in:baja,media,alta,urgente'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ], [
            'titulo.required' => 'El título del proceso es obligatorio.',
            'estado.required' => 'El estado es obligatorio.',
            'prioridad.required' => 'La prioridad es obligatoria.',
        ]);

        $proceso = ProcesoInterno::create([
            'titulo' => $data['titulo'],
            'descripcion' => $data['descripcion'] ?? null,
            'responsable_id' => $data['responsable_id'] ?? null,
            'estado' => $data['estado'],
            'prioridad' => $data['prioridad'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'created_by' => auth()->id(),
            'observaciones' => $data['observaciones'] ?? null,
        ]);

        return redirect()->route('admin.procesos.index')->with('success', 'El proceso interno se registró correctamente.');
    }

    public function edit(ProcesoInterno $proceso): View
    {
        return view('admin.procesos.edit', [
            'proceso' => $proceso,
            'responsables' => User::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ProcesoInterno $proceso): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'responsable_id' => ['nullable', 'exists:users,id'],
            'estado' => ['required', 'in:pendiente,en_proceso,completado,cancelado'],
            'prioridad' => ['required', 'in:baja,media,alta,urgente'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $proceso->update($data);

        return redirect()->route('admin.procesos.index')->with('success', 'El proceso interno se actualizó correctamente.');
    }

    public function destroy(ProcesoInterno $proceso): RedirectResponse
    {
        $proceso->delete();

        return redirect()->route('admin.procesos.index')->with('success', 'El proceso interno se eliminó correctamente.');
    }
}
