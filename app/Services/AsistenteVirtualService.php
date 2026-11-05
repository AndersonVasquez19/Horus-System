<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Support\ChatIntentMatcher;
use App\Support\ChatKnowledge;
use App\Support\FiltroPrivacidadChat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AsistenteVirtualService
{
    /** Aclaración ante un dato personal: genérica a propósito (nunca repite ni resume lo detectado). */
    public const RESPUESTA_PRIVACIDAD = 'Parece que incluiste un nombre o dato personal. Por seguridad, evita compartir información personal. Si deseas consultar sobre un curso o servicio, puedes reformular tu pregunta sin esos datos.';

    public const RESPUESTA_FALLBACK = 'No tengo un dato confirmado para esa consulta. Puedo orientarte sobre cursos, matrículas y servicios de Horus. También puedes consultar /contactos o llamar al +51 927 582 305.';

    public function __construct(
        private ?FiltroPrivacidadChat $privacidad = null,
        private ?HorusAdvisorClient $advisor = null,
    ) {
        $this->privacidad ??= new FiltroPrivacidadChat;
        $this->advisor ??= app(HorusAdvisorClient::class);
    }

    /** @return array{respuesta: string, intent: string|null, fuente: string, recomendaciones?: list<array{id: string, title: string, url_path: string|null}>} */
    public function procesarMensaje(string $mensaje, string $sessionId, string $scope = 'direct'): array
    {
        if (trim($mensaje) === '' || mb_strlen($mensaje) > 2000) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe una consulta de hasta 2000 caracteres.']);
        }

        $session = hash_hmac('sha256', $scope.'|'.$sessionId, (string) config('app.key'));
        // Decisión actual antes de lock/caché: ni una respuesta antigua ni una sesión ocupada la omiten.
        if ($this->privacidad->contieneDatosPersonales($mensaje)) {
            $result = $this->reply(self::RESPUESTA_PRIVACIDAD, null, 'respaldo');
            Log::info('chatbot.contingencia', ['motivo' => 'privacidad']);
            $this->registrar(['session_id' => $session, 'mensaje_usuario' => '[contenido omitido por privacidad]',
                'respuesta_bot' => $result['respuesta'], 'intent_detectado' => null, 'fuente' => 'respaldo']);
            $this->metric('respaldo');

            return $result;
        }
        $politica = '';
        foreach ([__FILE__, app_path('Support/FiltroPrivacidadChat.php'), app_path('Services/HorusAdvisorClient.php'),
            app_path('Support/Privacidad/LexicoPublico.php'), app_path('Support/Privacidad/lexico-publico.php'),
            base_path('chatbot-python/data/knowledge.json')] as $archivo) {
            $politica .= hash_file('sha256', $archivo);
        }
        $version = hash('sha256', 'privacy-before-cache-v2|'.$politica.serialize(config('horus-advisor')).serialize(config('chatbot.max_answer_chars', 1800))
            .serialize(Cache::get(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version'))));
        // La deduplicación identifica bytes exactos, sin conservar el mensaje.
        $key = 'chat:reply:'.hash_hmac('sha256', $session.'|'.$mensaje.'|'.$version, (string) config('app.key'));
        $lock = Cache::lock('chat:lock:'.$session, 30);
        if (! $lock->get()) {
            $this->metric('busy');

            return $this->reply('Tu consulta anterior sigue en proceso. Espera un momento antes de volver a enviarla.', null, 'ocupado');
        }
        try {
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['reply'], $cached['mac']) && is_array($cached['reply'])
                && is_string($cached['mac'])
                && hash_equals(hash_hmac('sha256', $key.serialize($cached['reply']), (string) config('app.key')), $cached['mac'])
                && $this->advisor->respuestaCacheValida($cached['reply'])) {
                $this->metric('duplicate');

                return $cached['reply'];
            }
            $result = $this->advisor->orientar($mensaje) ?? $this->reply(self::RESPUESTA_FALLBACK, null, 'respaldo');
            if ($result['fuente'] === 'respaldo') {
                $motivo = $this->advisor->motivoContingencia();
                Log::info('chatbot.contingencia', ['motivo' => $motivo ?? 'no_disponible']);
            }
            Cache::put($key, ['reply' => $result,
                'mac' => hash_hmac('sha256', $key.serialize($result), (string) config('app.key'))], (int) config('chatbot.duplicate_seconds', 60));
            $registro = [
                'session_id' => $session,
                'mensaje_usuario' => '[contenido omitido por privacidad]',
                'respuesta_bot' => $result['respuesta'],
                'intent_detectado' => $result['intent'],
            ];
            $registro['fuente'] = $result['fuente'];
            $this->registrar($registro);
            $this->metric($result['fuente']);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Diagnóstico histórico; no participa en procesarMensaje ni en rutas del chat. */
    public function buscarIntent(string $mensaje): ?ChatIntent
    {
        return (new ChatIntentMatcher)->buscar($mensaje, $this->intentsDisponibles());
    }

    /** @return array<int, ChatIntent> */
    private function intentsDisponibles(): array
    {
        $definitions = ChatKnowledge::intents();
        $configured = ChatIntent::query()->where('activo', true)
            ->orWhereIn('nombre', array_column($definitions, 'nombre'))->get()->keyBy('nombre');
        $intents = [];
        $builtinNames = [];

        foreach ($definitions as $definition) {
            $builtinNames[$definition['nombre']] = true;
            if (! $configured->has($definition['nombre']) || $configured->get($definition['nombre'])->activo) {
                $intents[] = new ChatIntent([...$definition, 'activo' => true]);
            }
        }

        foreach ($configured as $intent) {
            if ($intent->activo && ! isset($builtinNames[$intent->nombre])) {
                $intents[] = $intent;
            }
        }

        return $intents;
    }

    /** @return array{respuesta: string, intent: string|null, fuente: string} */
    private function reply(string $respuesta, ?string $intent, string $fuente): array
    {
        return ['respuesta' => $respuesta, 'intent' => $intent, 'fuente' => $fuente];
    }

    private function metric(string $outcome): void
    {
        Log::info('chatbot.metric', ['outcome' => $outcome]);
    }

    /** @param array<string, mixed> $registro */
    private function registrar(array $registro): void
    {
        $connection = (new ChatLog)->getConnection();
        try {
            // Savepoint si hay transacción externa: PostgreSQL no queda abortado
            // cuando una instalación aún no contiene la columna aditiva.
            $connection->transaction(fn () => ChatLog::create($registro));
        } catch (QueryException $error) {
            $cause = $error->getPrevious();
            $missing = $cause !== null && (
                ((string) $error->getCode() === '42703' && str_contains($cause->getMessage(), '"fuente"'))
                || ((string) $error->getCode() === 'HY000' && str_contains($cause->getMessage(), 'has no column named fuente'))
            );
            if (! $missing) {
                throw $error;
            }
            // Sin caché de esquema: una migración posterior se ve inmediatamente.
            unset($registro['fuente']);
            $connection->transaction(fn () => ChatLog::create($registro));
        }
    }
}
