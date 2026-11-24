<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\Estudiante;
use App\Models\User;
use App\Services\Estudiante360;
use App\Support\Entrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Vista 360° del estudiante: línea de tiempo (`estudiantes.ver`, cada tipo de evento según su propio permiso) y notas internas de
 * seguimiento (`estudiantes.crear` o `estudiantes.editar`).
 */
final class Estudiante360Controller extends Controller implements HasMiddleware
{
    public function __construct(private readonly Estudiante360 $vista) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:estudiantes.ver', only: ['linea']),
            new Middleware('permission:estudiantes.crear|estudiantes.editar', only: ['nota']),
        ];
    }

    public function linea(Request $request, int $estudiante): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->vista->lineaDeTiempo(Estudiante::query()->findOrFail($estudiante), $actor)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function nota(Request $request, int $estudiante): JsonResponse
    {
        $datos = $request->validate(['texto' => ['required', 'string', 'min:3', 'max:500', 'not_regex:/[<>]/u']], [
            'texto.required' => 'Escribe la nota.',
            'texto.min' => 'La nota debe tener al menos 3 caracteres.',
            'texto.max' => 'La nota admite hasta 500 caracteres.',
            'texto.not_regex' => 'La nota no puede contener etiquetas HTML.',
        ]);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->vista->agregarNota($actor, Estudiante::query()->findOrFail($estudiante), Entrada::texto($datos['texto']))], 201);
    }
}
