<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevisarSolicitudMatriculaRequest;
use App\Models\SolicitudMatricula;
use App\Services\GestionSolicitudesMatricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class SolicitudMatriculaController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:solicitudes-matricula.ver'),
            new Middleware('permission:solicitudes-matricula.gestionar', only: ['update']),
        ];
    }

    public function index(Request $request): Response
    {
        $filtros = $request->validate(['estado' => ['nullable', Rule::in(array_keys(SolicitudMatricula::ESTADOS))], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $solicitudes = SolicitudMatricula::query()->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->orderByDesc('id')->paginate(15)->withQueryString();

        return response()->view('admin.solicitudes-matricula.index', compact('solicitudes', 'filtros'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(RevisarSolicitudMatriculaRequest $request, SolicitudMatricula $solicitud, GestionSolicitudesMatricula $gestion): RedirectResponse
    {
        $gestion->revisar($solicitud->identificador, $request->validated(), $request->user());

        return redirect()->route('admin.solicitudes-matricula.index')->with('success', 'La revisión de la solicitud se guardó.');
    }
}
