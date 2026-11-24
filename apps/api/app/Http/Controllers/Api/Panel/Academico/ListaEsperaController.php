<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GestionListaEspera;
use App\Support\Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Lista de espera de una edición llena: ver (`convocatorias.ver`) y agregar o quitar (`matriculas.crear`). */
final class ListaEsperaController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionListaEspera $lista) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:convocatorias.ver', only: ['index']),
            new Middleware('permission:matriculas.crear', only: ['store', 'destroy']),
        ];
    }

    public function index(string $identificador): JsonResponse
    {
        return response()->json(['data' => $this->lista->listar($identificador)])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $identificador): JsonResponse
    {
        $datos = $request->validate([
            'estudiante_id' => ['required', 'integer', 'min:1'],
            'nota' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
        ], [
            'estudiante_id.required' => 'Selecciona el estudiante.',
            'nota.max' => 'La nota admite hasta 200 caracteres.',
            'nota.not_regex' => 'La nota no puede contener etiquetas HTML.',
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $nota = isset($datos['nota']) ? Entrada::texto($datos['nota']) : null;

        return response()->json(['data' => $this->lista->agregar($actor, $identificador, Entrada::entero($datos['estudiante_id']), $nota)], 201);
    }

    public function destroy(Request $request, string $identificador, string $entrada): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->lista->quitar($actor, $identificador, $entrada);

        return response()->json(['message' => 'La persona salió de la lista de espera.']);
    }
}
