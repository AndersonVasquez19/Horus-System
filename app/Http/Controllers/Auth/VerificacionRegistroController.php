<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmarRegistroRequest;
use App\Http\Requests\ReenviarRegistroRequest;
use App\Services\VerificacionRegistroService;
use Illuminate\Http\JsonResponse;

class VerificacionRegistroController extends Controller
{
    public function reenviar(ReenviarRegistroRequest $request, VerificacionRegistroService $service): JsonResponse
    {
        $service->solicitar($request->validated('email'));

        return response()->json(['message' => VerificacionRegistroService::SOLICITUD_RECIBIDA], 202);
    }

    public function confirmar(ConfirmarRegistroRequest $request, VerificacionRegistroService $service): JsonResponse
    {
        $data = $request->validated();
        if (! $service->confirmar($data['token'], $data['name'], $data['password'])) {
            return response()->json(['message' => 'El código no es válido o ha caducado.'], 422);
        }

        return response()->json(['message' => VerificacionRegistroService::CONFIRMACION_COMPLETA]);
    }
}
