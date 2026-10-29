<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class HorusAdvisorClient
{
    // Consultas construidas por Laravel: nunca se transmite texto libre, historial o sesión.
    private const CONSULTAS = [
        'consulta_cursos' => 'Oriéntame sobre los cursos de Horus.',
        'consulta_modalidades' => 'Oriéntame sobre las modalidades de formación de Horus.',
        'consulta_capacitaciones' => 'Oriéntame sobre las capacitaciones de Horus.',
        'consulta_servicios' => 'Oriéntame sobre los servicios tecnológicos de Horus.',
        'consulta_camaras' => 'Oriéntame sobre el servicio de cámaras de seguridad de Horus.',
        'consulta_cableado' => 'Oriéntame sobre el servicio de cableado estructurado de Horus.',
        'consulta_soporte' => 'Oriéntame sobre el servicio de soporte y mantenimiento de Horus.',
    ];

    private const RUTAS_PUBLICAS = [
        '/', '/quienes-somos', '/contactos', '/educacion/cursos', '/educacion/capacitaciones',
        '/educacion/asesoramiento', '/tecnologias/cableado-estructurado',
        '/tecnologias/camaras-seguridad', '/tecnologias/soporte-mantenimiento', '/preguntas-frecuentes',
    ];

    /** @return array{respuesta: string, intent: null, fuente: string}|null */
    public function orientar(string $intent): ?array
    {
        if (! config('horus-advisor.enabled') || ! isset(self::CONSULTAS[$intent])) {
            return null;
        }
        $inicio = microtime(true);
        $ready = $this->solicitar('GET', '/ready', [], $inicio);
        // Sin invalidación segura no se aceptan recomendaciones de catálogos cargados.
        if (($ready['status'] ?? null) !== 'ready' || ($ready['knowledge'] ?? null) !== 'loaded'
            || ($ready['auth'] ?? null) !== 'configured' || ($ready['catalog'] ?? null) !== 'none'
            || ! in_array($ready['semantic'] ?? null, ['loaded', 'loading', 'disabled', 'unavailable'], true)) {
            return null;
        }
        $reply = $this->solicitar('POST', '/api/v1/chat', ['message' => self::CONSULTAS[$intent], 'history' => []], $inicio);
        if ($reply === null || ! $this->respuestaValida($reply)) {
            return null;
        }

        return ['respuesta' => $reply['answer'], 'intent' => null, 'fuente' => 'advisor'];
    }

    /**
     * Validación en seco: no sincroniza ni publica. El llamador aporta un snapshot autorizado.
     *
     * @param  array<string, mixed>  $catalogo
     * @return array{valid: bool, signature: string, loaded: false}|null
     */
    public function validarCatalogo(array $catalogo): ?array
    {
        if (! config('horus-advisor.enabled')) {
            return null;
        }
        try {
            if (! app(FirmaCatalogoAdvisor::class)->verificar($catalogo)
                || strlen(json_encode($catalogo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
                return null;
            }
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
        $result = $this->solicitar('POST', '/api/v1/catalog/validate', $catalogo, microtime(true));
        if ($result === null || ($result['valid'] ?? null) !== true || ($result['signature'] ?? null) !== 'valid'
            || ($result['loaded'] ?? null) !== false || ($result['errors'] ?? null) !== []) {
            return null;
        }

        return ['valid' => true, 'signature' => 'valid', 'loaded' => false];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function solicitar(string $metodo, string $ruta, array $payload, float $inicio): ?array
    {
        $url = config('horus-advisor.url');
        $token = config('horus-advisor.token');
        // IP literal exacta: sin DNS/rebinding, userinfo, redirecciones o destinos de metadatos.
        if (! is_string($url) || ! preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $url, $match)
            || (int) $match[1] > 65535 || ! is_string($token) || strlen($token) < 32
            || preg_match('/[\r\n]/', $token)) {
            return null;
        }
        $restante = min(3.0, max(0.1, (float) config('horus-advisor.timeout', 2.5))) - (microtime(true) - $inicio);
        if ($restante <= 0) {
            return null;
        }
        $limite = min(65536, max(1024, (int) config('horus-advisor.max_response_bytes', 65536)));
        try {
            $response = Http::acceptJson()->withHeaders(['X-Horus-Service-Token' => $token, 'Accept-Encoding' => 'identity'])
                ->connectTimeout(min(0.5, max(0.05, (float) config('horus-advisor.connect_timeout', 0.4)), $restante))
                ->timeout($restante)->withOptions([
                    'allow_redirects' => false, 'proxy' => '', 'cookies' => false, 'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($limite): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limite
                            || ! in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) {
                            throw new RuntimeException('Respuesta excesiva.');
                        }
                    },
                    'progress' => static function (float $total, float $recibidos) use ($limite): void {
                        if ($total > $limite || $recibidos > $limite) {
                            throw new RuntimeException('Respuesta excesiva.');
                        }
                    },
                ])->send($metodo, $url.$ruta, $metodo === 'POST' ? ['json' => $payload] : []);
            if (! $response->successful() || strlen($response->body()) > $limite
                || ! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))) {
                return null;
            }
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);

            return is_array($data) && ! array_is_list($data) ? $data : null;
        } catch (Throwable) {
            // Nunca registrar excepciones: pueden contener token, cuerpo o configuración.
            return null;
        }
    }

    /** @param array<string, mixed> $reply */
    private function respuestaValida(array $reply): bool
    {
        if (! is_string($reply['answer'] ?? null) || trim($reply['answer']) === ''
            || mb_strlen($reply['answer']) > min(1800, (int) config('chatbot.max_answer_chars', 1800))
            || ! is_string($reply['intent'] ?? null) || ! preg_match('/\A[a-z_]{1,64}\z/D', $reply['intent'])
            || ! in_array($reply['mode'] ?? null, ['lexical', 'hybrid'], true)
            || ! in_array($reply['match_level'] ?? null, ['alta', 'media'], true)
            || ($reply['abstained'] ?? null) !== false || ($reply['needs_clarification'] ?? null) !== false
            || ! is_array($reply['sources'] ?? null) || ! array_is_list($reply['sources']) || $reply['sources'] === []
            || count($reply['sources']) > 10 || ! is_array($reply['recommendations'] ?? null)
            || ! array_is_list($reply['recommendations']) || count($reply['recommendations']) > 5) {
            return false;
        }
        // No publicar HTML, enlaces arbitrarios, PII o secretos de una respuesta defectuosa.
        $texto = $reply['answer'];
        if (preg_match('~<|>|@|https?://|\d[\d\s-]{6,}\d|\b(token|traceback|password|api_key)\b~iu', $texto)) {
            return false;
        }
        $ids = [];
        foreach ($reply['sources'] as $source) {
            if (! is_array($source) || ! $this->referenciaValida($source)) {
                return false;
            }
            $ids[] = $source['id'];
        }
        foreach ($reply['recommendations'] as $recomendacion) {
            if (! is_array($recomendacion) || ! $this->referenciaValida($recomendacion)
                || ! in_array($recomendacion['id'], $ids, true)
                || ! is_string($recomendacion['reason'] ?? null) || trim($recomendacion['reason']) === ''
                || ! is_string($recomendacion['kind'] ?? null) || ! in_array($recomendacion['kind'], ['course', 'training_program', 'service', 'advisory'], true)
                || ! is_array($recomendacion['evidence'] ?? null) || $recomendacion['evidence'] === []
                || ! array_is_list($recomendacion['evidence'])
                || ! in_array($recomendacion['match_level'] ?? null, ['alta', 'media', 'baja', 'ninguna'], true)) {
                return false;
            }
            foreach ($recomendacion['evidence'] as $id) {
                if (! is_string($id) || ! in_array($id, $ids, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $source */
    private function referenciaValida(array $source): bool
    {
        return is_string($source['id'] ?? null) && (bool) preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $source['id'])
            && ! str_starts_with($source['id'], 'cat_')
            && is_string($source['title'] ?? null) && trim($source['title']) !== '' && mb_strlen($source['title']) <= 200
            && in_array($source['url_path'] ?? null, self::RUTAS_PUBLICAS, true);
    }
}
