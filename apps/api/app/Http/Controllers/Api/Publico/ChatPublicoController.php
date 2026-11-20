<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Services\AsistenteVirtualService;
use App\Services\PresentacionRecomendacionesHorus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chat público para el BFF de apps/web (Fase 2b): reutiliza exactamente el motor de `POST /chat/mensaje`
 * (`AsistenteVirtualService`: barrera de privacidad, contingencia institucional y Horus AI en Python) sin sesión ni
 * CSRF, porque solo lo llama el servidor de Next con el token de servicio.
 *
 * La IA solo orienta: este endpoint no crea ni modifica matrículas, cotizaciones, reclamaciones ni ningún dato
 * empresarial. La respuesta se reduce a campos de presentación; nunca expone la intención interna ni el detalle de
 * un fallo (un error técnico devuelve 503 con un código genérico).
 */
final class ChatPublicoController extends Controller
{
    public function mensaje(Request $request, AsistenteVirtualService $asistente, PresentacionRecomendacionesHorus $presentacion): JsonResponse
    {
        $datos = $request->validate([
            'mensaje' => ['required', 'string', 'max:2000'],
            'session_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{8,64}\z/'],
        ], [
            'mensaje.required' => 'Escribe un mensaje para el asistente.',
            'mensaje.max' => 'El mensaje no debe superar los 2000 caracteres.',
            'session_id.*' => 'La sesión del chat no es válida.',
        ]);
        $visitante = $this->visitante($request);

        try {
            $resultado = $presentacion->paraFrontend(
                $asistente->procesarMensaje($datos['mensaje'], $datos['session_id'], 'web:'.$visitante),
            );
        } catch (Throwable) {
            // Solo un código fijo: nunca excepción, mensaje del usuario ni respuesta del modelo en logs.
            Log::warning('chat_bff_fallo_tecnico');

            return response()->json(['error' => ['code' => 'chat_no_disponible', 'message' => 'El asistente no está disponible.']], 503);
        }

        $salida = ['respuesta' => $resultado['respuesta'], 'fuente' => $resultado['fuente']];
        if (isset($resultado['tarjetas']) && is_array($resultado['tarjetas'])) {
            $salida['tarjetas'] = $resultado['tarjetas'];
        }

        return response()->json($salida);
    }

    /** Identificador opaco del visitante que calcula el BFF (HMAC de su IP, nunca la IP); sin él, se agrupa por origen. */
    public static function visitante(Request $request): string
    {
        $valor = $request->header('X-Horus-Visitante');

        return is_string($valor) && preg_match('/\A[a-f0-9]{64}\z/D', $valor) === 1 ? $valor : 'anonimo';
    }
}
