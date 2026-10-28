<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CatalogoPublicoCursos;
use Illuminate\Http\Response;

class CursosPublicosController extends Controller
{
    public function __invoke(CatalogoPublicoCursos $catalogo): Response
    {
        return response()->view('pages.educacion.cursos', ['cursosPublicos' => $catalogo->vigentes()])
            ->header('Cache-Control', 'no-store, private');
    }
}
