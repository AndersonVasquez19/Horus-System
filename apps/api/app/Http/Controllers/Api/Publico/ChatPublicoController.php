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

        if ($resultado['fuente'] === 'ocupado') {
            // La consulta anterior de esta sesión aún se procesa: no es una respuesta del asistente, sino «espera».
            // El BFF lo traduce en «espera un momento» y no lo muestra como si fuera una contestación.
            return response()->json(['error' => ['code' => 'consulta_en_proceso', 'message' => 'Tu consulta anterior sigue en proceso.']], 429)
                ->header('Retry-After', '3');
        }

        $salida = ['respuesta' => $resultado['respuesta'], 'fuente' => $resultado['fuente']];
        if (isset($resultado['tarjetas']) && is_array($resultado['tarjetas'])) {
            $salida['tarjetas'] = $resultado['tarjetas'];
        }
        if (isset($resultado['enlaces']) && is_array($resultado['enlaces'])) {
            $salida['enlaces'] = array_values(array_filter($resultado['enlaces'], 'is_string'));
        }
        if (isset($resultado['sugerencias']) && is_array($resultado['sugerencias']) && $resultado['sugerencias'] !== []) {
            $salida['sugerencias'] = array_values(array_filter($resultado['sugerencias'], 'is_string'));
        }

        $referencia = $asistente->referenciaRespuesta();
        if ($referencia !== null) {
            $salida['respuesta_id'] = $referencia;
        }

        return response()->json($salida);
    }

    /** 👍/👎 sobre una respuesta propia: solo guarda el voto en el registro; no cambia nada más. */
    public function voto(Request $request, AsistenteVirtualService $asistente): JsonResponse
    {
        $datos = $request->validate([
            'session_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{8,64}\z/'],
            'respuesta_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,17}\.[a-f0-9]{64}\z/'],
            'voto' => ['required', 'string', 'in:up,down'],
        ]);
        return $this->resultadoDeMetrica($asistente->registrarVoto($datos['respuesta_id'], $datos['voto'], $datos['session_id'],
            'web:'.$this->visitante($request)));
    }

    /** Clic en un botón de WhatsApp de una respuesta propia: un sí o no en el registro, sin número ni mensaje. */
    public function contacto(Request $request, AsistenteVirtualService $asistente): JsonResponse
    {
        $datos = $request->validate([
            'session_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{8,64}\z/'],
            'respuesta_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,17}\.[a-f0-9]{64}\z/'],
        ]);

        return $this->resultadoDeMetrica($asistente->registrarContactoWhatsapp($datos['respuesta_id'], $datos['session_id'],
            'web:'.$this->visitante($request)));
    }

    /** @param 'registrado'|'no_encontrado'|'no_disponible' $resultado */
    private function resultadoDeMetrica(string $resultado): JsonResponse
    {
        return match ($resultado) {
            'registrado' => response()->json(['registrado' => true]),
            'no_encontrado' => response()->json(['error' => ['code' => 'respuesta_no_encontrada', 'message' => 'No se encontró esa respuesta.']], 404),
            default => response()->json(['error' => ['code' => 'metrica_no_disponible', 'message' => 'No se pudo guardar.']], 503),
        };
    }

    /** Identificador opaco del visitante que calcula el BFF (HMAC de su IP, nunca la IP); sin él, se agrupa por origen. */
    public static function visitante(Request $request): string
    {
        $valor = $request->header('X-Horus-Visitante');

        return is_string($valor) && preg_match('/\A[a-f0-9]{64}\z/D', $valor) === 1 ? $valor : 'anonimo';
    }
}
