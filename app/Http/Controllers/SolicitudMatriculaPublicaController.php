<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSolicitudMatriculaRequest;
use App\Services\GestionSolicitudesMatricula;
use App\Support\FormularioSolicitudMatricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class SolicitudMatriculaPublicaController extends Controller
{
    public function create(GestionSolicitudesMatricula $gestion): Response
    {
        $habilitado = config('catalogo-publico.enabled') && Schema::hasTable('solicitudes_matricula');

        return response()->view('pages.educacion.solicitar-matricula', [
            'habilitado' => $habilitado, 'cursos' => $habilitado ? $gestion->cursos() : [],
            'marca' => FormularioSolicitudMatricula::emitir(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
    }

    public function store(StoreSolicitudMatriculaRequest $request, GestionSolicitudesMatricula $gestion): RedirectResponse
    {
        $solicitud = $gestion->registrar($request->validated());

        return redirect()->route('matricula-publica.create')
            ->with('solicitud_matricula_recibida', $solicitud->identificador)
            ->header('Cache-Control', 'no-store, private');
    }
}
