<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Servicios;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Panel\ResumenServicios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard tecnológico. Solo el permiso de entrada: cada bloque se filtra por el permiso de su módulo.
 */
final class ResumenController extends Controller
{
    public function __invoke(Request $request, ResumenServicios $resumen): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $resumen->para($user)]);
    }
}
