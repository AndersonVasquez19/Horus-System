<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Support\ChatIntentMatcher;
use App\Support\ChatKnowledge;
use App\Support\Entrada;
use App\Support\FiltroPrivacidadChat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AsistenteVirtualService
{
    /** Aclaración ante un dato personal: genérica a propósito (nunca repite ni resume lo detectado). */
    public const RESPUESTA_PRIVACIDAD = 'Parece que incluiste un nombre o dato personal. Por seguridad, evita compartir información personal. Si deseas consultar sobre un curso o servicio, puedes reformular tu pregunta sin esos datos.';

    /** Intento de manipulación o pedido de datos internos bloqueado antes de Horus AI: negativa clara, no «no disponible». */
    public const RESPUESTA_SEGURIDAD = 'No puedo cambiar mis reglas ni compartir datos personales o información interna. Sí puedo ayudarte con cursos, capacitaciones, asesoramiento, convenios, pagos y contacto de Horus Group.';

    /** Memoria de la conversación (foco y estado firmados): diez minutos sin escribir y el asistente empieza de cero. */
    private const MEMORIA_SEGUNDOS = 600;

    public const RESPUESTA_FALLBACK = 'Ahora mismo no puedo responder esa consulta. Puedo ayudarte con cursos, capacitaciones y atención al cliente. Escríbenos al +51 972 021 374 o a horusgroupcajamarca@gmail.com y un asesor te atenderá.';

    private FiltroPrivacidadChat $privacidad;

    private ?string $referencia = null;

    private HorusAdvisorClient $advisor;

    public function __construct(?FiltroPrivacidadChat $privacidad = null, ?HorusAdvisorClient $advisor = null)
    {
        $this->privacidad = $privacidad ?? new FiltroPrivacidadChat;
        $this->advisor = $advisor ?? app(HorusAdvisorClient::class);
    }

    /** @return array{respuesta: string, intent: string|null, fuente: string, recomendaciones?: list<array{id: string, title: string, url_path: string|null}>, enlaces?: list<string>, sugerencias?: list<string>} */
    public function procesarMensaje(string $mensaje, string $sessionId, string $scope = 'direct'): array
    {
        if (trim($mensaje) === '' || mb_strlen($mensaje) > 2000) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe una consulta de hasta 2000 caracteres.']);
        }

        $this->referencia = null;
        $session = $this->hashSesion($sessionId, $scope);
        $canal = str_starts_with($scope, 'admin:') ? 'admin' : 'web';
        // Decisión actual antes de lock/caché: ni una respuesta antigua ni una sesión ocupada la omiten.
        if ($this->privacidad->contieneDatosPersonales($mensaje)) {
            $result = $this->reply(self::RESPUESTA_PRIVACIDAD, null, 'respaldo');
            Log::info('chatbot.contingencia', ['motivo' => 'privacidad']);
            $this->registrarSinInterrumpir(['session_id' => $session, 'mensaje_usuario' => '[contenido omitido por privacidad]',
                'respuesta_bot' => $result['respuesta'], 'intent_detectado' => null, 'fuente' => 'respaldo', 'canal' => $canal]);
            $this->metric('respaldo');

            return $result;
        }
        $politica = $this->firmaPoliticaArchivos([
            __FILE__,
            app_path('Support/FiltroPrivacidadChat.php'),
            app_path('Services/HorusAdvisorClient.php'),
            app_path('Support/Privacidad/LexicoPublico.php'),
            app_path('Support/Privacidad/lexico-publico.php'),
            Entrada::texto(config('horus-knowledge.path')),
        ]);
        $version = hash('sha256', 'privacy-before-cache-v2|'.$politica.serialize(config('horus-advisor')).serialize(config('chatbot.max_answer_chars', 1800))
            .serialize(Cache::get(Entrada::texto(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version')))));
        // La deduplicación identifica bytes exactos, sin conservar el mensaje.
        $key = 'chat:reply:'.hash_hmac('sha256', $session.'|'.$mensaje.'|'.$version, Entrada::texto(config('app.key')));
        $lock = Cache::lock('chat:lock:'.$session, 30);
        if (! $lock->get()) {
            $this->metric('busy');

            return $this->reply('Tu consulta anterior sigue en proceso. Espera un momento antes de volver a enviarla.', null, 'ocupado');
        }
        try {
            $this->advisor->iniciarMensaje();
            $focoKey = 'chat:focus:'.hash_hmac('sha256', $session.'|'.$version, Entrada::texto(config('app.key')));
            $focoGuardado = Cache::get($focoKey);
            $foco = [];
            if (is_array($focoGuardado) && is_array($focoGuardado['ids'] ?? null)
                && array_is_list($focoGuardado['ids']) && count($focoGuardado['ids']) <= 2
                && is_string($focoGuardado['mac'] ?? null)
                && hash_equals(hash_hmac('sha256', $focoKey.serialize($focoGuardado['ids']), Entrada::texto(config('app.key'))), $focoGuardado['mac'])) {
                $foco = array_values(array_filter($focoGuardado['ids'], 'is_string'));
            }
            // Estado conversacional (22/11/2026): temas, nivel, modalidad y registro en foco, sin texto del usuario.
            $estadoKey = 'chat:state:'.hash_hmac('sha256', $session.'|'.$version, Entrada::texto(config('app.key')));
            $estado = $this->leerFirmado($estadoKey, 'state');
            $consulta = $foco === [] ? $mensaje : $this->advisor->resolverSeguimiento($mensaje, $foco, $estado !== []);
            // La misma frase con otro contexto es otra consulta: el estado entra en la clave de deduplicación.
            $clave = static fn (string $texto, array $contexto): string => 'chat:reply:'.hash_hmac('sha256',
                $session.'|'.$texto.'|'.$version.($contexto === [] ? '' : '|'.serialize($contexto)), Entrada::texto(config('app.key')));
            $key = $clave($consulta, $estado);
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['reply'], $cached['mac']) && is_array($cached['reply'])
                && is_string($cached['mac'])
                && hash_equals(hash_hmac('sha256', $key.serialize($cached['reply']), Entrada::texto(config('app.key'))), $cached['mac'])
                && $this->advisor->respuestaCacheValida($cached['reply'])) {
                $focoCache = $cached['focus'] ?? [];
                if (is_array($focoCache) && array_is_list($focoCache) && count($focoCache) <= 2
                    && is_string($cached['focus_mac'] ?? null)
                    && hash_equals(hash_hmac('sha256', $key.serialize($focoCache), Entrada::texto(config('app.key'))), $cached['focus_mac'])) {
                    $this->guardarFoco($focoKey, array_values(array_filter($focoCache, 'is_string')));
                } else {
                    Cache::forget($focoKey);
                }
                $estadoCache = $cached['state'] ?? [];
                $this->guardarFirmado($estadoKey, 'state', is_array($estadoCache)
                    && is_string($cached['state_mac'] ?? null)
                    && hash_equals(hash_hmac('sha256', $key.serialize($estadoCache), Entrada::texto(config('app.key'))), $cached['state_mac'])
                    ? $estadoCache : []);
                $this->metric('duplicate');
                $referenciaPrevia = $cached['ref'] ?? null; // la firma se comprueba al votar
                $this->referencia = is_string($referenciaPrevia) ? $referenciaPrevia : null;

                return $cached['reply'];
            }
            $result = $this->advisor->orientar($consulta, null, $estado)
                ?? $this->reply($this->advisor->motivoContingencia() === 'seguridad' ? self::RESPUESTA_SEGURIDAD : self::RESPUESTA_FALLBACK, null, 'respaldo');
            $nuevoFoco = $result['fuente'] === 'advisor' ? $this->advisor->focoConversacional() : [];
            $this->guardarFoco($focoKey, $nuevoFoco);
            $nuevoEstado = $result['fuente'] === 'advisor' ? $this->advisor->estadoConversacional() : [];
            $this->guardarFirmado($estadoKey, 'state', $nuevoEstado);
            if ($result['fuente'] === 'respaldo') {
                $motivo = $this->advisor->motivoContingencia();
                Log::info('chatbot.contingencia', ['motivo' => $motivo ?? 'no_disponible']);
                $causa = $this->advisor->causaFallo();
                if ($causa !== null) {
                    // Solo una categoría fija y la latencia: nunca texto del usuario ni detalles de la excepción.
                    Log::info('chatbot.advisor_fallo', ['causa' => $causa, 'latencia_ms' => $this->advisor->latenciaMs()]);
                }
            }
            $registro = [
                'session_id' => $session,
                'mensaje_usuario' => '[contenido omitido por privacidad]',
                'respuesta_bot' => $result['respuesta'],
                'intent_detectado' => $result['fuente'] === 'advisor' ? $this->advisor->intencionDetectada() : $result['intent'],
                'canal' => $canal,
            ];
            $registro['fuente'] = $result['fuente'];
            $this->registrarSinInterrumpir($registro);
            $this->guardarRespuesta($key, $result, $nuevoFoco, $nuevoEstado);
            if ($consulta === $mensaje && $nuevoEstado !== $estado) {
                // Doble envío: al repetir el mensaje el estado ya es el de esta respuesta; también se deduplica.
                $this->guardarRespuesta($clave($mensaje, $nuevoEstado), $result, $nuevoFoco, $nuevoEstado);
            }
            $this->metric($result['fuente']);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $ids */
    private function guardarFoco(string $key, array $ids): void
    {
        if ($ids === []) {
            Cache::forget($key);

            return;
        }
        Cache::put($key, ['ids' => $ids,
            'mac' => hash_hmac('sha256', $key.serialize($ids), Entrada::texto(config('app.key')))], self::MEMORIA_SEGUNDOS);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $foco
     * @param  array<string, mixed>  $estado
     */
    private function guardarRespuesta(string $key, array $result, array $foco, array $estado): void
    {
        $secreto = Entrada::texto(config('app.key'));
        Cache::put($key, ['reply' => $result, 'focus' => $foco,
            'focus_mac' => hash_hmac('sha256', $key.serialize($foco), $secreto),
            'state' => $estado,
            'ref' => $this->referencia,
            'state_mac' => hash_hmac('sha256', $key.serialize($estado), $secreto),
            'mac' => hash_hmac('sha256', $key.serialize($result), $secreto)], Entrada::entero(config('chatbot.duplicate_seconds', 60)));
    }

    /**
     * Valor de caché autenticado con HMAC (5 minutos). Solo estructuras de vocabulario cerrado, nunca texto libre.
     *
     * @return array<string, mixed>
     */
    private function leerFirmado(string $key, string $campo): array
    {
        $guardado = Cache::get($key);
        if (! is_array($guardado) || ! is_array($guardado[$campo] ?? null) || ! is_string($guardado['mac'] ?? null)
            || ! hash_equals(hash_hmac('sha256', $key.serialize($guardado[$campo]), Entrada::texto(config('app.key'))), $guardado['mac'])) {
            return [];
        }

        /** @var array<string, mixed> */
        return $guardado[$campo];
    }

    /** @param array<mixed> $valor */
    private function guardarFirmado(string $key, string $campo, array $valor): void
    {
        if ($valor === []) {
            Cache::forget($key);

            return;
        }
        Cache::put($key, [$campo => $valor,
            'mac' => hash_hmac('sha256', $key.serialize($valor), Entrada::texto(config('app.key')))], self::MEMORIA_SEGUNDOS);
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
            $existente = $configured->get($definition['nombre']);
            if ($existente === null || $existente->activo) {
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

    /**
     * Firma SHA-256 de los archivos de política/conocimiento, cacheada por mtime+size para evitar
     * re-leer y hashear ~650 KB de disco en cada consulta sin perder invalidación inmediata al editar.
     *
     * @param  list<string>  $archivos
     */
    private function firmaPoliticaArchivos(array $archivos): string
    {
        $meta = [];
        foreach ($archivos as $archivo) {
            $meta[] = $archivo.'|'.((string) @filemtime($archivo)).'|'.((string) @filesize($archivo));
        }
        $clave = 'chat:policy_hash:'.hash('sha256', implode("\n", $meta));

        return Entrada::texto(Cache::remember($clave, 300, static function () use ($archivos): string {
            $concat = '';
            foreach ($archivos as $archivo) {
                $concat .= hash_file('sha256', $archivo);
            }

            return $concat;
        }));
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

    /**
     * El registro es auditoría, no parte de la respuesta: si la base falla al guardarlo, la persona igual recibe la
     * respuesta ya calculada (antes el fallo terminaba en un 503). Solo se anota una categoría fija, sin texto.
     *
     * @param  array<string, mixed>  $registro
     */
    private function registrarSinInterrumpir(array $registro): void
    {
        try {
            $fila = $this->registrar($registro);
            $id = Entrada::texto($fila->getKey());
            $this->referencia = $id.'.'.$this->firmaReferencia($id, Entrada::texto($registro['session_id'] ?? ''));
        } catch (Throwable) {
            Log::warning('chatbot.registro_fallo');
        }
    }

    /** Referencia firmada de la última respuesta registrada: es lo único que permite votarla. */
    public function referenciaRespuesta(): ?string
    {
        return $this->referencia;
    }

    /**
     * Guarda el 👍/👎 de una respuesta. La referencia está firmada y ligada a la sesión que la recibió, así que nadie
     * puede votar filas ajenas. Solo cuenta el primer voto.
     *
     * @return 'registrado'|'no_encontrado'|'no_disponible'
     */
    public function registrarVoto(string $referencia, string $voto, string $sessionId, string $scope): string
    {
        [$id, $firma] = array_pad(explode('.', $referencia, 2), 2, '');
        $session = $this->hashSesion($sessionId, $scope);
        if (! hash_equals($this->firmaReferencia($id, $session), $firma)) {
            return 'no_encontrado';
        }
        try {
            $consulta = ChatLog::query()->whereKey((int) $id)->where('session_id', $session);
            if (! (clone $consulta)->exists()) {
                return 'no_encontrado';
            }
            $consulta->whereNull('voto')->update(['voto' => $voto]);
        } catch (QueryException) {
            // La migración de métricas aún no está aplicada: el voto se pierde, el chat sigue igual.
            Log::warning('chatbot.voto_no_disponible');

            return 'no_disponible';
        }

        return 'registrado';
    }

    private function hashSesion(string $sessionId, string $scope): string
    {
        return hash_hmac('sha256', $scope.'|'.$sessionId, Entrada::texto(config('app.key')));
    }

    private function firmaReferencia(string $id, string $session): string
    {
        return hash_hmac('sha256', 'chat-voto|'.$id.'|'.$session, Entrada::texto(config('app.key')));
    }

    /** @param array<string, mixed> $registro */
    private function registrar(array $registro): ChatLog
    {
        $connection = (new ChatLog)->getConnection();
        $opcionales = ['fuente', 'canal'];
        while (true) {
            try {
                // Savepoint si hay transacción externa: PostgreSQL no queda abortado
                // cuando una instalación aún no contiene una columna aditiva.
                return $connection->transaction(fn (): ChatLog => ChatLog::create($registro));
            } catch (QueryException $error) {
                $ausente = $this->columnaAusente($error, array_values(array_intersect($opcionales, array_keys($registro))));
                if ($ausente === null) {
                    throw $error;
                }
                // Sin caché de esquema: una migración posterior se ve inmediatamente.
                unset($registro[$ausente]);
            }
        }
    }

    /** @param list<string> $candidatas */
    private function columnaAusente(QueryException $error, array $candidatas): ?string
    {
        $causa = $error->getPrevious();
        if ($causa === null) {
            return null;
        }
        foreach ($candidatas as $columna) {
            if (((string) $error->getCode() === '42703' && str_contains($causa->getMessage(), '"'.$columna.'"'))
                || ((string) $error->getCode() === 'HY000'
                    && preg_match('/has no column named '.$columna.'\b/', $causa->getMessage()) === 1)) {
                return $columna;
            }
        }

        return null;
    }
}
