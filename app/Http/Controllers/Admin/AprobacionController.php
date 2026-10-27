<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AprobacionInterna;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class AprobacionController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:aprobaciones.ver', only: ['index']),
            new Middleware('permission:aprobaciones.gestionar', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $query = AprobacionInterna::query()->with(['solicitante', 'aprobador']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('busqueda')) {
            $busqueda = trim((string) $request->input('busqueda'));
            $query->where(function ($q) use ($busqueda): void {
                $q->where('titulo', 'like', "%{$busqueda}%")
                    ->orWhere('tipo', 'like', "%{$busqueda}%")
                    ->orWhere('descripcion', 'like', "%{$busqueda}%")
                    ->orWhere('comentario', 'like', "%{$busqueda}%");
            });
        }

        $aprobaciones = $query
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 1 WHEN 'aprobado' THEN 2 WHEN 'rechazado' THEN 3 ELSE 4 END")
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.aprobaciones.index', [
            'aprobaciones' => $aprobaciones,
            'estado' => $request->input('estado'),
            'busqueda' => $request->input('busqueda'),
            'usuarios' => User::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string'],
            'solicitante_id' => ['nullable', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'exists:users,id'],
            'comentario' => ['nullable', 'string'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:pendiente,aprobado,rechazado'],
        ]);

        $data['estado'] = $data['estado'] ?? 'pendiente';

        AprobacionInterna::create([
            'titulo' => $data['titulo'],
            'tipo' => $data['tipo'],
            'descripcion' => $data['descripcion'] ?? null,
            'solicitante_id' => $data['solicitante_id'] ?? auth()->id(),
            'aprobador_id' => $data['aprobador_id'] ?? null,
            'comentario' => $data['comentario'] ?? null,
            'referencia' => $data['referencia'] ?? null,
            'estado' => $data['estado'],
        ]);

        return redirect()->route('admin.aprobaciones.index')->with('success', 'La solicitud de aprobación se registró correctamente.');
    }

    public function update(Request $request, AprobacionInterna $aprobacion): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string'],
            'solicitante_id' => ['nullable', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'exists:users,id'],
            'comentario' => ['nullable', 'string'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', 'in:pendiente,aprobado,rechazado'],
        ]);

        $aprobacion->update($data);

        return redirect()->route('admin.aprobaciones.index')->with('success', 'La solicitud de aprobación se actualizó correctamente.');
    }

    public function destroy(AprobacionInterna $aprobacion): RedirectResponse
    {
        $aprobacion->delete();

        return redirect()->route('admin.aprobaciones.index')->with('success', 'La solicitud de aprobación se eliminó correctamente.');
    }
}
