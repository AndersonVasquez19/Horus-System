<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Panel\ResumenAcademico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard académico. Solo exige el permiso de entrada al panel: cada bloque se incluye únicamente si el usuario
 * tiene el permiso del módulo (ResumenAcademico), de modo que nadie recibe datos que no podría consultar.
 */
final class ResumenController extends Controller
{
    public function __invoke(Request $request, ResumenAcademico $resumen): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $resumen->para($user)]);
    }
}
