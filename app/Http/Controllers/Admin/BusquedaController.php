<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class BusquedaController extends Controller implements HasMiddleware
{
    /**
     * El grupo /admin ya exige sesión y rol administrativo; se conserva el requisito de sesión del controlador.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth'),
        ];
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
            $usuario = $request->user();

            // Cada sección se consulta solo si el usuario puede ver ese módulo.
            if ($usuario->can('estudiantes.ver')) {
                $resultados['estudiantes'] = Estudiante::query()
                    ->whereLike('nombres', "%{$busqueda}%")
                    ->orWhereLike('apellidos', "%{$busqueda}%")
                    ->orWhereLike('dni', "%{$busqueda}%")
                    ->orderBy('apellidos')
                    ->limit(8)
                    ->get();
            }

            if ($usuario->can('cursos.ver')) {
                $resultados['cursos'] = Curso::query()
                    ->whereLike('nombre', "%{$busqueda}%")
                    ->orWhereLike('codigo', "%{$busqueda}%")
                    ->limit(8)
                    ->get();
            }

            if ($usuario->can('matriculas.ver')) {
                $resultados['matriculas'] = Matricula::query()
                    // Coincidencia parcial por ID, como antes; en PostgreSQL compila a "id"::text ilike.
                    ->whereLike('id', "%{$busqueda}%")
                    ->orWhereHas('estudiante', fn ($query) => $query->whereLike('nombres', "%{$busqueda}%")
                        ->orWhereLike('apellidos', "%{$busqueda}%"))
                    ->orWhereHas('curso', fn ($query) => $query->whereLike('nombre', "%{$busqueda}%"))
                    ->with(['estudiante', 'curso'])
                    ->limit(8)
                    ->get();
            }

            if ($usuario->can('usuarios.ver')) {
                $resultados['usuarios'] = User::query()
                    ->whereLike('name', "%{$busqueda}%")
                    ->orWhereLike('email', "%{$busqueda}%")
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
