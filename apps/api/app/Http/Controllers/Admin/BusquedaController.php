<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use Illuminate\Database\Eloquent\Builder;
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
        $busqueda = ListFilter::text($request, 'q');

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
                $estudiantes = Estudiante::query();
                BusquedaLike::contiene($estudiantes, ['nombres', 'apellidos', 'dni'], $busqueda);
                $resultados['estudiantes'] = $estudiantes->orderBy('apellidos')->limit(8)->get();
            }

            if ($usuario->can('cursos.ver')) {
                $cursos = Curso::query();
                BusquedaLike::contiene($cursos, ['nombre', 'codigo'], $busqueda);
                $resultados['cursos'] = $cursos->limit(8)->get();
            }

            if ($usuario->can('matriculas.ver')) {
                $resultados['matriculas'] = Matricula::query()
                    ->where(function (Builder $query) use ($busqueda): void {
                        // Coincidencia parcial por ID, como antes (el id se compara como texto).
                        BusquedaLike::contiene($query, ['id'], $busqueda, comoTexto: ['id']);
                        $query->orWhereHas('estudiante', fn (Builder $estudiante) => BusquedaLike::contiene($estudiante, ['nombres', 'apellidos'], $busqueda))
                            ->orWhereHas('curso', fn (Builder $curso) => BusquedaLike::contiene($curso, ['nombre'], $busqueda));
                    })
                    ->with(['estudiante', 'curso'])
                    ->limit(8)
                    ->get();
            }

            if ($usuario->can('usuarios.ver')) {
                $usuarios = User::query();
                BusquedaLike::contiene($usuarios, ['name', 'email'], $busqueda);
                $resultados['usuarios'] = $usuarios->limit(8)->get();
            }
        }

        return view('admin.busqueda.index', [
            'busqueda' => $busqueda,
            'resultados' => $resultados,
        ]);
    }
}
