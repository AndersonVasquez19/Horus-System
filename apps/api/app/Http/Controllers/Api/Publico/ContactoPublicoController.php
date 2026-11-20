<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactoPublicoRequest;
use App\Models\AuditLog;
use App\Models\MensajeContacto;
use App\Models\NotificacionInterna;
use App\Models\User;
use App\Support\Entrada;
use App\Support\LimitesEnvioPublico;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * «Contáctanos» para el BFF de apps/web: guarda el mensaje para que el equipo lo atienda desde el panel (bandeja de
 * reclamaciones) y avisa a quien tiene `reclamaciones.ver`. No hay ruta pública para consultar mensajes. Ni la auditoría ni
 * las notificaciones llevan el texto escrito por la persona.
 */
final class ContactoPublicoController extends Controller
{
    public function store(StoreContactoPublicoRequest $request): JsonResponse
    {
        // Topes estrictos tras validar, por visitante (HMAC del BFF) y por medio de contacto (hash): equivocarse al rellenar no bloquea.
        $visitante = ChatPublicoController::visitante($request);
        $medio = strtolower(Entrada::texto(($request->input('correo') ?: $request->input('telefono'))));
        $limites = [
            'contacto-bff-v10:'.$visitante => [3, 600],
            'contacto-bff-vdia:'.$visitante => [8, 86400],
            'contacto-bff-medio:'.hash('sha256', $medio) => [5, 86400],
        ];
        $superada = LimitesEnvioPublico::consumir($limites);
        if ($superada !== null) {
            return response()->json(
                ['error' => ['code' => 'demasiadas_solicitudes', 'message' => 'Se alcanzó el límite de mensajes. Inténtalo más tarde.']],
                429,
                ['Retry-After' => (string) LimitesEnvioPublico::espera($superada), 'Cache-Control' => 'no-store, private'],
            );
        }

        $datos = $request->validated();
        $mensaje = DB::transaction(function () use ($datos, $visitante): MensajeContacto {
            $mensaje = MensajeContacto::create([
                'nombre' => trim(Entrada::texto($datos['nombre'])),
                'telefono' => ($datos['telefono'] ?? null) ?: null,
                'correo' => isset($datos['correo']) && $datos['correo'] !== '' ? strtolower(Entrada::texto($datos['correo'])) : null,
                'asunto' => $datos['asunto'],
                'mensaje' => trim(Entrada::texto($datos['mensaje'])),
                'visitante' => $visitante === 'anonimo' ? null : $visitante,
            ]);
            AuditLog::record('contacto_recibido', MensajeContacto::class, $mensaje->id, ['asunto' => $mensaje->asunto], null);

            return $mensaje;
        });

        $this->avisar($mensaje);

        return response()->json(['recibido' => true, 'mensaje' => 'Recibimos tu mensaje. Te responderemos a la brevedad.'], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    /** Un fallo al avisar no pierde el mensaje: ya está guardado. */
    private function avisar(MensajeContacto $mensaje): void
    {
        try {
            if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
                return;
            }
            foreach (User::permission('reclamaciones.ver')->get() as $usuario) {
                NotificacionInterna::crearPersonal((int) $usuario->id, [
                    'titulo' => 'Nuevo mensaje de contacto',
                    'mensaje' => 'Alguien escribió desde «Contáctanos» (asunto: '.$mensaje->asunto.').',
                    'tipo' => 'contacto',
                    'vinculo' => '/admin/academico/reclamaciones?vista=mensajes',
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar de un mensaje de contacto.', ['excepcion' => $e::class]);
        }
    }
}
