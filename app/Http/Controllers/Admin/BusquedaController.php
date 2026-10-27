<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusquedaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->input('q'));

        $resultados = [
            'estudiantes' => collect(),
            'cursos' => collect(),
            'matriculas' => collect(),
            'usuarios' => collect(),
        ];

        if ($busqueda !== '') {
            $resultados['estudiantes'] = Estudiante::query()
                ->where('nombres', 'like', "%{$busqueda}%")
                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                ->orWhere('dni', 'like', "%{$busqueda}%")
                ->orderBy('apellidos')
                ->limit(8)
                ->get();

            $resultados['cursos'] = Curso::query()
                ->where('nombre', 'like', "%{$busqueda}%")
                ->orWhere('codigo', 'like', "%{$busqueda}%")
                ->limit(8)
                ->get();

            $resultados['matriculas'] = Matricula::query()
                ->where('id', 'like', "%{$busqueda}%")
                ->orWhereHas('estudiante', fn ($query) => $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%"))
                ->orWhereHas('curso', fn ($query) => $query->where('nombre', 'like', "%{$busqueda}%"))
                ->with(['estudiante', 'curso'])
                ->limit(8)
                ->get();

            if (auth()->user()->can('usuarios.ver')) {
                $resultados['usuarios'] = User::query()
                    ->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('email', 'like', "%{$busqueda}%")
                    ->limit(8)
                    ->get();
            }
        }

        return view('admin.busqueda.index', [
            'busqueda' => $busqueda,
            'resultados' => $resultados,
        ]);
    }
}
