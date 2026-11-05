<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CatalogoPublicoCursos;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CursosPublicosController extends Controller
{
    public function __invoke(CatalogoPublicoCursos $catalogo): Response
    {
        $habilitado = (bool) config('catalogo-publico.enabled', false);
        $cursos = [];
        $fallo = false;
        $total = 0;
        if ($habilitado) {
            try {
                // El servicio admite instalaciones sin tabla; la web debe hacer visible ese fallo técnico.
                if (! Schema::hasTable('curso_publicaciones')) {
                    $fallo = true;
                    Log::warning('catalogo_web_esquema_incompleto');
                } else {
                    $resultado = $catalogo->paraWeb();
                    foreach ($resultado['cursos'] as $ficha) {
                        // Defensa ante datos antiguos importados sin pasar por el editor.
                        if (trim($ficha['titulo']) === '' || mb_strlen($ficha['titulo']) > 120
                            || trim($ficha['resumen']) === '' || mb_strlen($ficha['resumen']) > 500
                            || ! in_array($ficha['modalidad'], ['presencial', 'semipresencial', 'virtual', 'hibrido'], true)
                            || mb_strlen($ficha['horario'] ?? '') > 200 || mb_strlen($ficha['inscripcion'] ?? '') > 400) {
                            Log::warning('catalogo_web_ficha_invalida');

                            continue;
                        }
                        $total++;
                        if (count($cursos) < 50) {
                            $cursos[] = $ficha;
                        }
                    }
                }
            } catch (QueryException) {
                // No registrar SQL, bindings, credenciales ni datos editoriales.
                $fallo = true;
                $cursos = [];
                Log::warning('catalogo_web_lectura_fallida');
            }
        }

        return response()->view('pages.educacion.cursos', [
            'catalogoHabilitado' => $habilitado, 'catalogoFallo' => $fallo,
            'cursosPublicos' => $cursos, 'totalCursosPublicos' => $total,
        ])
            ->header('Cache-Control', 'no-store, private');
    }
}
