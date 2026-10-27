<?php

namespace App\Http\Controllers;

use App\Services\AsistenteVirtualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatWidgetController extends Controller
{
    public function mensajePublico(Request $request, AsistenteVirtualService $asistente): JsonResponse
    {
        $datos = $request->validate(
            [
                'mensaje' => ['required', 'string', 'max:2000'],
                'session_id' => ['required', 'string', 'max:64'],
            ],
            [
                'mensaje.required' => 'Escribe un mensaje para el asistente.',
                'mensaje.string' => 'El mensaje debe ser texto.',
                'mensaje.max' => 'El mensaje no debe superar los 2000 caracteres.',
                'session_id.required' => 'La sesión del chat no es válida.',
                'session_id.string' => 'La sesión del chat no es válida.',
                'session_id.max' => 'La sesión del chat no es válida.',
            ],
        );

        return response()->json($asistente->procesarMensaje($datos['mensaje'], $datos['session_id']));
    }
}
