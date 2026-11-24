<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Services\RiesgoDesercion;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Riesgo de deserción de los matriculados en ediciones en curso (`matriculas.ver`). Solo lectura. */
final class RiesgoController extends Controller implements HasMiddleware
{
    public function __construct(private readonly RiesgoDesercion $riesgo) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:matriculas.ver', only: ['index'])];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->riesgo->evaluar()])->header('Cache-Control', 'no-store, private');
    }
}
