<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Envía un mensaje al chat público por su única ruta vigente (`POST /api/public/v1/chat`, la del BFF de apps/web).
 * La ruta de Blade `POST /chat/mensaje` se retiró en la Fase 1 del retiro de Blade; el motor es el mismo
 * (`AsistenteVirtualService`), pero esta ruta exige el token de servicio y un `session_id` de 8 a 64 caracteres.
 */
trait ChatPublicoBff
{
    /**
     * @param  array<string, mixed>  $cuerpo  `mensaje` y `session_id`; un identificador corto se completa para cumplir el formato.
     */
    protected function postChatPublico(array $cuerpo, ?string $visitante = null): TestResponse
    {
        $token = 'token-de-prueba-bff-0123456789abcdefghij';
        config(['horus-web.service_token' => $token]);

        if (isset($cuerpo['session_id']) && is_string($cuerpo['session_id']) && $cuerpo['session_id'] !== '' && strlen($cuerpo['session_id']) < 8) {
            $cuerpo['session_id'] .= '-0123456789';
        }

        return $this->postJson(
            '/api/public/v1/chat',
            $cuerpo,
            ['X-Horus-Web-Token' => $token] + ($visitante === null ? [] : ['X-Horus-Visitante' => $visitante]),
        );
    }
}
