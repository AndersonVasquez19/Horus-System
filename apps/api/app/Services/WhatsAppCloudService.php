<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
use Illuminate\Support\Facades\Http;

/**
 * Cliente para WhatsApp Cloud API (Meta) limitado exclusivamente a plantillas aprobadas (Ola 3).
 *
 * No envía texto libre fuera de ventana ni opera sin consentimiento explícito del destinatario (Ley N.º 29733) y bandera activa.
 */
class WhatsAppCloudService
{
    public function configured(): bool
    {
        return config('recordatorios.whatsapp.enabled') === true
            && is_string(config('recordatorios.whatsapp.phone_number_id'))
            && trim((string) config('recordatorios.whatsapp.phone_number_id')) !== ''
            && is_string(config('recordatorios.whatsapp.token'))
            && trim((string) config('recordatorios.whatsapp.token')) !== '';
    }

    public static function normalizarTelefono(string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 9 && str_starts_with($digitos, '9')) {
            return '51'.$digitos;
        }

        return strlen($digitos) >= 10 && strlen($digitos) <= 15 ? $digitos : null;
    }

    /**
     * @param  list<string>  $parametrosCuerpo
     * @return array{ok: bool, status: int, provider_id: string|null, retryable: bool}
     */
    public function enviarPlantilla(string $telefono, string $plantilla, array $parametrosCuerpo): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'provider_id' => null, 'retryable' => false];
        }

        $destino = self::normalizarTelefono($telefono);
        if ($destino === null) {
            return ['ok' => false, 'status' => 422, 'provider_id' => null, 'retryable' => false];
        }

        $version = trim(Entrada::texto(config('recordatorios.whatsapp.version', 'v21.0')));
        $phoneId = trim(Entrada::texto(config('recordatorios.whatsapp.phone_number_id')));
        $token = trim(Entrada::texto(config('recordatorios.whatsapp.token')));
        $idioma = trim(Entrada::texto(config('recordatorios.whatsapp.idioma', 'es_PE')));

        $componentes = $parametrosCuerpo === []
            ? []
            : [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $valor): array => ['type' => 'text', 'text' => $valor],
                    $parametrosCuerpo,
                ),
            ]];

        $response = Http::withToken($token)->acceptJson()
            ->connectTimeout(3)->timeout(12)->withoutRedirecting()
            ->post("https://graph.facebook.com/{$version}/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $destino,
                'type' => 'template',
                'template' => [
                    'name' => $plantilla,
                    'language' => ['code' => $idioma],
                    ...($componentes !== [] ? ['components' => $componentes] : []),
                ],
            ]);

        $msgId = $response->json('messages.0.id');
        if ($response->successful() && is_string($msgId) && $msgId !== '') {
            return ['ok' => true, 'status' => $response->status(), 'provider_id' => substr($msgId, 0, 100), 'retryable' => false];
        }

        $status = $response->status();

        return [
            'ok' => false,
            'status' => $status,
            'provider_id' => null,
            'retryable' => $status === 429 || $status >= 500,
        ];
    }
}
