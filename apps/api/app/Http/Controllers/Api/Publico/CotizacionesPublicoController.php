<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCotizacionPublicaRequest;
use App\Services\RecepcionCotizacionesPublicas;
use App\Support\FormularioCotizacionPublica;
use App\Support\LimitesEnvioPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Solicitud de cotización para el BFF de apps/web (Fase 2c.3). Solo envuelve {@see RecepcionCotizacionesPublicas}, que
 * registra en el módulo comercial existente (`solicitudes_cotizacion`, bandeja `/admin/cotizaciones`). Es una
 * SOLICITUD de cotización, no una cotización emitida: sin precios, sin documentos ni compromisos económicos. No existe
 * ruta pública para consultar solicitudes, así que nadie puede ver las de otra persona.
 */
final class CotizacionesPublicoController extends Controller
{
    /** Servicios que se pueden solicitar y la marca de un solo uso del formulario (apagado: sin marca ni servicios). */
    public function formulario(RecepcionCotizacionesPublicas $recepcion): JsonResponse
    {
        $habilitado = $recepcion->habilitado();

        return response()->json([
            'habilitado' => $habilitado,
            'marca' => $habilitado ? FormularioCotizacionPublica::emitir() : null,
            'servicios' => $habilitado ? $recepcion->servicios() : [],
            'max_servicios' => RecepcionCotizacionesPublicas::MAX_SERVICIOS,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreCotizacionPublicaRequest $request, RecepcionCotizacionesPublicas $recepcion): JsonResponse
    {
        // Límites estrictos tras validar (incremento atómico); por visitante (HMAC del BFF) y por correo (hash).
        $visitante = ChatPublicoController::visitante($request);
        $limites = [
            'cotizaciones-bff-v10:'.$visitante => [5, 600],
            'cotizaciones-bff-vdia:'.$visitante => [10, 86400],
            'cotizaciones-bff-correo:'.hash('sha256', (string) $request->input('email')) => [5, 86400],
        ];
        $superada = LimitesEnvioPublico::consumir($limites);
        if ($superada !== null) {
            return response()->json(
                ['error' => ['code' => 'demasiadas_solicitudes', 'message' => 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.']],
                429,
                ['Retry-After' => (string) LimitesEnvioPublico::espera($superada), 'Cache-Control' => 'no-store, private'],
            );
        }

        try {
            ['solicitud' => $solicitud, 'repetida' => $repetida] = $recepcion->registrar($request->validated());
        } catch (ValidationException $e) {
            LimitesEnvioPublico::devolver($limites);

            throw $e;
        }
        if ($repetida) {
            LimitesEnvioPublico::devolver($limites);
        }

        return response()->json([
            'codigo' => $solicitud->codigo,
            'recibida_at' => $solicitud->recibida_at->toIso8601String(),
            'repetida' => $repetida,
        ], $repetida ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }
}
