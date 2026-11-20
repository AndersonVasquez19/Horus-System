<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReclamacionBffRequest;
use App\Services\GestionReclamaciones;
use App\Support\AntispamReclamaciones;
use App\Support\Email;
use App\Support\Entrada;
use App\Support\Reclamaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Libro de Reclamaciones para el BFF de apps/web (Fase 2c.1). Solo envuelve el módulo existente: la validación vive
 * en {@see StoreReclamacionBffRequest} (que extiende la del sitio Blade) y el registro, la numeración, la
 * idempotencia, la copia por correo, el historial y la auditoría en {@see GestionReclamaciones::registrarPublica()}.
 * Las reclamaciones siguen en el módulo central compartido, sin clasificar por dominio: ni este endpoint ni Horus AI
 * asignan, clasifican, cierran o modifican reclamaciones.
 *
 * Es solo de ENVÍO: no existe ninguna ruta pública para consultar una reclamación (ni propia ni ajena). La respuesta
 * nunca contiene datos personales; solo número, tipo, fecha límite y si el envío ya estaba registrado.
 */
final class ReclamacionesPublicoController extends Controller
{
    /**
     * Estado del libro y credenciales de un solo uso del formulario (marca firmada + token de envío).
     * Apagado, no emite ninguna: no hay nada que firmar y el cliente muestra el libro físico de respaldo.
     */
    public function formulario(): JsonResponse
    {
        $habilitado = Reclamaciones::habilitado();

        return response()->json([
            'habilitado' => $habilitado,
            'marca' => $habilitado ? AntispamReclamaciones::emitirMarca() : null,
            'token_envio' => $habilitado ? (string) Str::uuid() : null,
            'correo_activo' => $habilitado && Reclamaciones::envioHabilitado(),
            'plazo_dias_habiles' => Entrada::entero(config('reclamaciones.plazo_dias_habiles', 15)),
            'honeypot' => Entrada::texto(config('reclamaciones.antispam.honeypot', 'sitio_web')),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreReclamacionBffRequest $request, GestionReclamaciones $gestion): JsonResponse
    {
        // El límite cuenta solo envíos que ya pasaron la validación: equivocarse al rellenar no bloquea al consumidor.
        // (Los intentos inválidos tienen su propio tope amplio en la ruta: `reclamaciones-bff-intentos`.)
        // Se incrementa primero (operación atómica de la caché) y después se compara, para que ráfagas concurrentes
        // no pasen todas la comprobación; un reenvío del mismo token (repetida) devuelve su cupo al final.
        $claves = $this->claves($request);
        $bloqueada = null;
        foreach ($claves as $clave => $maximo) {
            if (RateLimiter::hit($clave, $this->ventana($clave)) > $maximo) {
                $bloqueada ??= $clave;
            }
        }
        if ($bloqueada !== null) {
            return response()->json(
                ['error' => ['code' => 'demasiadas_solicitudes', 'message' => 'Se alcanzó el límite de envíos. Inténtalo más tarde.']],
                429,
                ['Retry-After' => (string) max(1, RateLimiter::availableIn($bloqueada)), 'Cache-Control' => 'no-store, private'],
            );
        }

        ['reclamacion' => $reclamacion, 'repetida' => $repetida] = $gestion->registrarPublica(
            $request->datosReclamacion(),
            $request->tokenEnvio(),
        );
        if ($repetida) {
            foreach (array_keys($claves) as $clave) {
                RateLimiter::decrement($clave);
            }
        }

        return response()->json([
            'numero' => $reclamacion->numero,
            'tipo' => $reclamacion->tipo,
            'fecha_limite' => $reclamacion->fecha_limite->toDateString(),
            'repetida' => $repetida,
            'correo_activo' => Reclamaciones::envioHabilitado(),
        ], $repetida ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Claves de límite → máximo. Por visitante (HMAC que calcula el BFF; nunca la IP) y por correo normalizado
     * (guardado como hash para no dejar datos personales en la caché).
     *
     * @return array<string, int>
     */
    private function claves(StoreReclamacionBffRequest $request): array
    {
        $visitante = ChatPublicoController::visitante($request);
        $correo = hash('sha256', Email::normalize($request->input('email')));

        return [
            'reclamaciones-bff-v10:'.$visitante => Entrada::entero(config('reclamaciones.antispam.limite_ip_10min', 3)),
            'reclamaciones-bff-vdia:'.$visitante => Entrada::entero(config('reclamaciones.antispam.limite_ip_dia', 10)),
            'reclamaciones-bff-correo:'.$correo => Entrada::entero(config('reclamaciones.antispam.limite_correo_dia', 3)),
        ];
    }

    private function ventana(string $clave): int
    {
        return str_starts_with($clave, 'reclamaciones-bff-v10:') ? 600 : 86400;
    }
}
