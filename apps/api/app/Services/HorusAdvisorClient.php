<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
use App\Support\FiltroPrivacidadChat;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** @phpstan-type ReferenciaRecomendada array{id: string, title: string, url_path: string|null} */
class HorusAdvisorClient
{
    /** @var list<string> Identificadores públicos validados; nunca texto de una conversación. */
    private array $focoConversacional = [];

    /** @return list<string> */
    public function focoConversacional(): array
    {
        return $this->focoConversacional;
    }

    /** @var array<string, mixed> Estado estructurado que devolvió Horus AI (vocabulario cerrado; nunca texto del usuario). */
    private array $estadoConversacional = [];

    /** Claves del estado conversacional y su forma (contrato de ConversationState de Horus AI). */
    private const ESTADO_LISTAS = ['topics' => 8, 'objectives' => 8];

    private const ESTADO_CLAVES = ['level' => 'desconocido', 'modality' => 'cualquiera', 'audience' => 'desconocido'];

    private const ESTADO_EDADES = ['profile_age', 'cue_age', 'focus_age'];

    /** @return array<string, mixed> */
    public function estadoConversacional(): array
    {
        return $this->estadoConversacional;
    }

    /**
     * Seguimiento por frases en PHP. Con estado conversacional, Horus AI ya resuelve «¿cuánto dura?» o «¿y eso es
     * virtual?» sobre el tema anterior; aquí solo quedan «sí» y «la primera/la segunda» (elegir entre opciones).
     *
     * @param  list<string>  $foco
     */
    public function resolverSeguimiento(string $mensaje, array $foco, bool $conEstado = false): string
    {
        $normalizado = trim(preg_replace('/[¿?¡!.,;:]+/u', ' ', $this->normalizar($mensaje)) ?? '');
        $normalizado = preg_replace('/\s+/u', ' ', $normalizado) ?? '';
        $afirmacion = in_array($normalizado, ['si', 'si por favor', 'claro', 'correcto', 'exacto', 'eso', 'esa', 'ese', 'ese curso', 'esa capacitacion', 'ese servicio'], true);
        $referencia = ! $conEstado && preg_match(
            '/\A(?:y |pero |entonces |osea )?(?:mas detalles|dame detalles|detalles|mas info(?:rmacion)?|info(?:rmacion)?|de que trata|que es|resumen|temario|que incluye|que contiene|que abarca|que cubre|que (?:se )?aprende|que tipos(?: hay| tienen)?|cuales son|cuanto cuesta|cuanto vale|cuanto sale|cuanto dura|cuando (?:empieza|inicia)|hasta cuando(?: es| esta| dura)?|donde(?: es| queda)?|como (?:me )?(?:matriculo|inscribo)|como se (?:matricula|inscribe)|(?:(?:cual es |que )?(?:el |la |su )?)?(?:precio|costo|valor|modalidad|horario|duracion|requisitos|vigencia|inscripcion|matricula|ubicacion)|a que hora(?: es)?|que dias(?: es)?|es (?:virtual|presencial|semipresencial|online|hibrido)(?: o (?:virtual|presencial|semipresencial|online))?|(?:de |sobre )?(?:ese|esa|eso|el|la) (?:curso|capacitacion|servicio))\z/u',
            $normalizado,
        ) === 1;
        $opcion = match ($normalizado) {
            'la primera', 'primera opcion', 'el primero', 'primer curso' => 0,
            'la segunda', 'segunda opcion', 'el segundo', 'segundo curso' => 1,
            default => null,
        };
        if ((! $afirmacion && ! $referencia && $opcion === null) || count($foco) > 2 || $foco === []) {
            return $mensaje;
        }
        if ($foco === ['cat_listado_formacion'] && $opcion !== null) {
            $titulo = $this->tituloDeCursoListado($opcion);

            return $titulo === null ? $mensaje : 'Información sobre '.$titulo;
        }
        if (count($foco) !== 1 && $opcion === null) {
            return $mensaje;
        }
        $id = $foco[$opcion ?? 0] ?? null;
        if (! is_string($id)) {
            return $mensaje;
        }
        $titulo = $this->tituloDeFoco($id);
        if ($titulo === null) {
            return $mensaje;
        }

        return $afirmacion || $opcion !== null ? 'Información sobre '.$titulo : $mensaje.' de '.$titulo;
    }

    private function tituloDeCursoListado(int $indice): ?string
    {
        $cursos = array_values(array_filter(
            $this->fuentesDelMensaje(),
            static fn (array $f, string $k): bool => str_starts_with($k, 'cat_curso:') || str_starts_with($k, 'cat_curso_'),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $cursos[$indice]['title'] ?? null;
    }

    private function tituloDeFoco(string $id): ?string
    {
        if (str_starts_with($id, 'cat_')) {
            $fuentes = $this->fuentesDelMensaje();
            if ($id === 'cat_listado_formacion') {
                $cursos = array_values(array_filter(
                    $fuentes,
                    static fn (array $f, string $k): bool => str_starts_with($k, 'cat_curso:') || str_starts_with($k, 'cat_curso_'),
                    ARRAY_FILTER_USE_BOTH,
                ));
                if (count($cursos) === 1) {
                    return $cursos[0]['title'];
                }
            }

            return $fuentes[$id]['title'] ?? null;
        }

        return self::conocimiento()['porId'][$id]['title'] ?? null;
    }

    /**
     * Fichas del catálogo ya construidas en el mensaje en curso, con la versión esperada con que se obtuvieron.
     * Construirlas cuesta (consulta, permisos, privacidad y firma: ~28 ms con 50 fichas, ~165 ms con 500), y un mismo
     * mensaje puede necesitarlas dos veces (seguimiento con «sí»/«la primera» y validación de la respuesta). Nunca se
     * conservan entre mensajes: una ficha retirada deja de citarse en el mensaje siguiente.
     *
     * @var array{version: mixed, fuentes: array<string, array{title: string, url_path: string|null, answer: string, fragments?: list<string>}>}|null
     */
    private ?array $fuentesMensaje = null;

    /** Inicio de un mensaje del visitante: descarta las fichas memorizadas del anterior. */
    public function iniciarMensaje(): void
    {
        $this->fuentesMensaje = null;
    }

    /** @return array<string, array{title: string, url_path: string|null, answer: string, fragments?: list<string>}> */
    private function fuentesDelMensaje(): array
    {
        $version = Cache::get(Entrada::texto(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version')));
        if ($this->fuentesMensaje === null || $this->fuentesMensaje['version'] !== $version) {
            $this->fuentesMensaje = ['version' => $version, 'fuentes' => app(FuentesCatalogoAdvisor::class)->actuales()];
        }

        return $this->fuentesMensaje['fuentes'];
    }

    private ?string $motivoContingencia = null;

    /** Categoría fija del último fallo técnico (nunca el mensaje de una excepción ni texto del usuario). */
    private ?string $causaFallo = null;

    private ?int $latenciaMs = null;

    private const CLAVE_FALLOS = 'horus-advisor.circuito.fallos';

    private const CLAVE_ABIERTO = 'horus-advisor.circuito.abierto';

    private const MENSAJE_EXCESIVA = 'Respuesta excesiva.';

    /** Causas de transporte que cuentan para abrir el circuito. */
    private const CAUSAS_TRANSPORTE = ['conexion', 'tiempo_agotado', 'http_5xx'];

    /** Hosts que nunca se aceptan aunque se configuren: metadatos de nube y loopback por nombre. */
    private const HOSTS_PROHIBIDOS = ['localhost', 'metadata', 'metadata.google.internal', 'instance-data'];

    /** @var array<string, array{title: string, url_path: string|null, answer: string, fragments?: list<string>}> */
    private array $fuentesCatalogo = [];

    public function motivoContingencia(): ?string
    {
        return $this->motivoContingencia;
    }

    public function causaFallo(): ?string
    {
        return $this->causaFallo;
    }

    public function latenciaMs(): ?int
    {
        return $this->latenciaMs;
    }

    /** Revalidación actual de una respuesta de caché autenticada por el intermediario.
     *
     * @param  array<mixed>  $reply
     *
     * @phpstan-assert-if-true array{respuesta: string, intent: string|null, fuente: string, recomendaciones?: list<ReferenciaRecomendada>, enlaces?: list<string>} $reply
     */
    public function respuestaCacheValida(array $reply): bool
    {
        if (array_key_exists('enlaces', $reply)) {
            // Solo secciones del sitio de la lista blanca, y solo en respuestas del asistente.
            if (($reply['fuente'] ?? null) !== 'advisor' || ! is_array($reply['enlaces']) || ! array_is_list($reply['enlaces'])
                || $reply['enlaces'] === [] || count($reply['enlaces']) > 3) {
                return false;
            }
            foreach ($reply['enlaces'] as $ruta) {
                if (! is_string($ruta) || $ruta === '/' || ! in_array($ruta, self::RUTAS_PUBLICAS, true)) {
                    return false;
                }
            }
            unset($reply['enlaces']);
        }
        if (! in_array(array_keys($reply), [['respuesta', 'intent', 'fuente'], ['respuesta', 'intent', 'fuente', 'recomendaciones']], true) || ! is_string($reply['respuesta'])
            || $reply['intent'] !== null || ! in_array($reply['fuente'], ['advisor', 'respaldo'], true)
            || trim($reply['respuesta']) === '' || mb_strlen($reply['respuesta']) > min(1800, Entrada::entero(config('chatbot.max_answer_chars', 1800)))) {
            return false;
        }
        if ($reply['fuente'] === 'respaldo') {
            return ! array_key_exists('recomendaciones', $reply)
                && in_array($reply['respuesta'], [AsistenteVirtualService::RESPUESTA_FALLBACK, AsistenteVirtualService::RESPUESTA_PRIVACIDAD], true);
        }
        if (array_key_exists('recomendaciones', $reply)) {
            $this->fuentesCatalogo = app(FuentesCatalogoAdvisor::class)->actuales();
            if (! is_array($reply['recomendaciones']) || ! array_is_list($reply['recomendaciones']) || count($reply['recomendaciones']) > 5) {
                return false;
            }
            $ids = [];
            foreach ($reply['recomendaciones'] as $referencia) {
                if (! is_array($referencia) || array_keys($referencia) !== ['id', 'title', 'url_path']
                    || ! $this->referenciaEditorialRecomendada($referencia) || in_array($referencia['id'], $ids, true)) {
                    return false;
                }
                $ids[] = $referencia['id'];
            }
            // Misma comprobación de procedencia que la respuesta HTTP: texto editorial
            // exacto y vigente, sin HTML ni enlaces ajenos. No exime texto adicional.
            if ($reply['recomendaciones'] !== []
                && $this->esTextoVerificado(['answer' => $reply['respuesta'], 'sources' => $reply['recomendaciones']])) {
                return $this->enlacesDeTextoVerificado($reply['respuesta']);
            }
        }
        $texto = $this->sinEntidadesVerificadas(str_ireplace(self::CANALES_OFICIALES_PUBLICOS, '', $reply['respuesta']));

        return ! $this->privacidad->contieneDatosPrivadosEnRespuesta($texto)
            && ! $this->privacidad->contieneDatosPersonales($texto)
            && $this->enlacesValidos($reply['respuesta']);
    }

    private const RUTAS_PUBLICAS = [
        '/',
        '/#convenios',
        '/quienes-somos',
        '/galeria',
        '/contactos',
        '/educacion/cursos',
        '/educacion/capacitaciones',
        '/educacion/asesoramiento',
        '/tecnologias/cableado-estructurado',
        '/tecnologias/camaras-seguridad',
        '/tecnologias/soporte-mantenimiento',
        '/legales/preguntas-frecuentes',
        '/legales/libro-reclamaciones',
        '/legales/politicas-devolucion',
        '/legales/politicas-privacidad',
        '/legales/politicas-cookies',
    ];

    public const CANALES_OFICIALES_PUBLICOS = [
        'https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
        'https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula',
        'https://wa.me/51972021374',
        '+51 972 021 374',
        'horusgroupcajamarca@gmail.com',
        '20611010977',
    ];

    public function __construct(
        private FiltroPrivacidadChat $privacidad = new FiltroPrivacidadChat,
        /** @var (\Closure(): float)|null Reloj monotónico sustituible en pruebas, en segundos. */
        private ?\Closure $reloj = null,
    ) {}

    private function ahora(): float
    {
        return $this->reloj === null ? hrtime(true) / 1_000_000_000 : ($this->reloj)();
    }

    /**
     * @param  array<string, mixed>  $estado  estado devuelto por Horus AI en el turno anterior (ya validado al recibirlo)
     * @return array{respuesta: string, intent: null, fuente: string, recomendaciones?: list<ReferenciaRecomendada>}|null
     */
    public function orientar(string $mensajeOIntent, ?string $mensaje = null, array $estado = []): ?array
    {
        $this->focoConversacional = [];
        $this->estadoConversacional = [];
        $mensajeLimpio = trim($mensaje ?? $mensajeOIntent);
        $this->motivoContingencia = null;
        $this->causaFallo = null;
        $this->latenciaMs = null;
        if (! config('horus-advisor.enabled')) {
            $this->motivoContingencia = 'deshabilitado';

            return null;
        }
        if ($this->privacidad->contieneDatosPersonales($mensajeLimpio)) {
            $this->motivoContingencia = 'privacidad';

            return null;
        }
        if ($this->esBloqueadoPorSeguridad($mensajeLimpio)) {
            $this->motivoContingencia = 'seguridad';

            return null;
        }
        $this->motivoContingencia = 'no_disponible';
        if (Cache::has(self::CLAVE_ABIERTO)) {
            // Horus AI falló seguido hace poco: contingencia inmediata en vez de esperar otro timeout.
            $this->causaFallo = 'circuito_abierto';

            return null;
        }
        $inicio = $this->ahora();
        // La latencia observada usa su propio reloj: no consume lecturas del reloj de presupuesto.
        $inicioMedicion = hrtime(true);
        try {
            return $this->consultarAsesor($mensajeLimpio, $inicio, $estado);
        } finally {
            $this->latenciaMs = intdiv(hrtime(true) - $inicioMedicion, 1_000_000);
            $this->registrarResultadoCircuito();
            // Las fichas memorizadas no sobreviven a la consulta (ni a llamadas directas a orientar()).
            $this->fuentesMensaje = null;
        }
    }

    /**
     * @param  array<string, mixed>  $estado
     * @return array{respuesta: string, intent: null, fuente: string, recomendaciones?: list<ReferenciaRecomendada>}|null
     */
    private function consultarAsesor(string $mensajeLimpio, float $inicio, array $estado): ?array
    {
        $ready = $this->solicitar('GET', '/ready', [], $inicio);
        $sincronizacion = (bool) config('horus-catalog.sync_enabled', false);
        $estadoCatalogoValido = ($ready['catalog'] ?? null) === 'none' && ! $sincronizacion;
        // Catálogo caducado (22/11/2026): Python sigue atendiendo con el conocimiento institucional y descarta
        // las fichas vencidas. Aquí se trata como catálogo vacío: toda respuesta que cite un curso se rechaza.
        $catalogoCaducado = ($ready['catalog'] ?? null) === 'stale';
        if ($catalogoCaducado) {
            $estadoCatalogoValido = true;
        }
        if (($ready['catalog'] ?? null) === 'loaded') {
            $versionActiva = $ready['catalog_version'] ?? null;
            $estadoCatalogoValido = is_string($versionActiva)
                && preg_match('/\\A[a-f0-9]{40}\\z/D', $versionActiva) === 1;
            if ($sincronizacion) {
                $esperada = Cache::get(Entrada::texto(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version')));
                $estadoCatalogoValido = $estadoCatalogoValido && is_string($esperada)
                    && preg_match('/\\A[a-f0-9]{40}\\z/D', $esperada) === 1
                    && hash_equals($esperada, $versionActiva);
            }
        }
        if (($ready['status'] ?? null) !== 'ready' || ($ready['knowledge'] ?? null) !== 'loaded'
            || ($ready['auth'] ?? null) !== 'configured' || ! $estadoCatalogoValido
            || ! in_array($ready['semantic'] ?? null, ['loaded', 'loading', 'disabled', 'unavailable'], true)) {
            $this->causaFallo ??= 'no_listo';

            return null;
        }
        // Sin historial de texto: el contexto viaja como estado estructurado (solo si Horus AI lo devolvió antes).
        $payload = ['message' => $mensajeLimpio, 'history' => []];
        if ($estado !== []) {
            $payload['state'] = $estado;
        }
        $reply = $this->solicitar('POST', '/api/v1/chat', $payload, $inicio);
        if ($reply === null) {
            return null;
        }
        $this->fuentesCatalogo = $catalogoCaducado ? [] : $this->fuentesDelMensaje();
        if (! $this->respuestaValida($reply)) {
            $this->motivoContingencia = 'respuesta_rechazada';
            $this->causaFallo = 'validacion';

            return null;
        }
        $this->motivoContingencia = null;
        $this->estadoConversacional = $this->estadoValido($reply['state'] ?? null);

        $contexto = $reply['context'] ?? null;
        $foco = ($reply['needs_clarification'] ?? false)
            ? (is_array($contexto) ? ($contexto['focus_ids'] ?? []) : [])
            : array_column(Entrada::lista($reply['sources']), 'id');
        if (is_array($foco) && array_is_list($foco) && count($foco) <= 2) {
            foreach ($foco as $id) {
                if (is_string($id) && (str_starts_with($id, 'cat_')
                    ? isset($this->fuentesCatalogo[$id]) : isset(self::conocimiento()['porId'][$id]))) {
                    $this->focoConversacional[] = $id;
                }
            }
        }

        $respuestaTexto = Entrada::texto($reply['answer']);
        $pregunta = $reply['clarification_question'] ?? null;
        if (($reply['needs_clarification'] ?? false) && is_string($pregunta) && trim($pregunta) !== '') {
            $respuestaTexto = trim($respuestaTexto)."\n\n".trim($pregunta);
        }

        $resultado = ['respuesta' => $respuestaTexto, 'intent' => null, 'fuente' => 'advisor'];
        $referencias = [];
        foreach (Entrada::lista($reply['recommendations']) as $recomendacion) {
            if (is_array($recomendacion) && in_array($recomendacion['kind'] ?? null, ['course', 'training_program', 'service'], true)
                && $this->referenciaEditorialRecomendada($recomendacion)
                && is_string($recomendacion['id'] ?? null) && is_string($recomendacion['title'] ?? null)) {
                $ruta = $recomendacion['url_path'] ?? null;
                $referencias[$recomendacion['id']] = ['id' => $recomendacion['id'], 'title' => $recomendacion['title'], 'url_path' => is_string($ruta) ? $ruta : null];
            }
        }
        if ($referencias === []
            && ($reply['intent'] ?? null) === 'training_info'
            && array_column(Entrada::lista($reply['sources']), 'id') === ['cat_listado_formacion']
            && str_contains(Entrada::texto($reply['answer'] ?? ''), 'Modalidad:')) {
            $respuestaTexto = Entrada::texto($reply['answer'] ?? '');
            $cursosVigentes = array_filter(
                $this->fuentesCatalogo,
                static fn (array $f, string $k): bool => (str_starts_with($k, 'cat_curso:') || str_starts_with($k, 'cat_curso_'))
                    && $f['title'] !== ''
                    && str_contains($respuestaTexto, $f['title']),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($cursosVigentes !== [] && count($cursosVigentes) <= 5) {
                foreach ($cursosVigentes as $idCurso => $fichaCurso) {
                    $referencias[$idCurso] = [
                        'id' => $idCurso,
                        'title' => $fichaCurso['title'],
                        'url_path' => $fichaCurso['url_path'],
                    ];
                }
            }
        }
        if ($referencias !== []) {
            $resultado['recomendaciones'] = array_values($referencias);
        }
        // Enlaces a secciones del sitio: salen de las fuentes ya validadas, no de rutas escritas en el texto.
        $enlaces = [];
        foreach (Entrada::lista($reply['sources'] ?? []) as $fuente) {
            $ruta = is_array($fuente) ? ($fuente['url_path'] ?? null) : null;
            if (is_string($ruta) && $ruta !== '/' && in_array($ruta, self::RUTAS_PUBLICAS, true)) {
                $enlaces[$ruta] = $ruta;
            }
        }
        if ($enlaces !== []) {
            $resultado['enlaces'] = array_values($enlaces);
        }

        return $resultado;
    }

    /** Solo referencias editoriales actuales; nunca listados sintéticos ni registros administrativos.
     *
     * @param  array<mixed>  $referencia
     */
    private function referenciaEditorialRecomendada(array $referencia): bool
    {
        return is_string($referencia['id'] ?? null)
            && preg_match('/\Acat_(?:curso|servicio)_/', $referencia['id']) === 1
            && isset($this->fuentesCatalogo[$referencia['id']])
            && $this->referenciaValida($referencia)
            && (($referencia['url_path'] ?? null) === null || in_array($referencia['url_path'], self::RUTAS_PUBLICAS, true));
    }

    /**
     * Estado conversacional de Horus AI reducido a su forma permitida. Cualquier desviación (clave ajena, texto con
     * espacios, lista larga, registro desconocido) lo descarta entero: es preferible perder contexto que guardar algo
     * que no sea vocabulario cerrado. Los registros citados deben existir en el conocimiento o en el catálogo vigente.
     *
     * @return array<string, mixed>
     */
    private function estadoValido(mixed $estado): array
    {
        if (! is_array($estado) || array_is_list($estado)) {
            return [];
        }
        $permitidas = [...array_keys(self::ESTADO_LISTAS), ...array_keys(self::ESTADO_CLAVES), ...self::ESTADO_EDADES,
            'goal', 'focus_ids', 'out_of_scope', 'quote'];
        if (array_diff(array_keys($estado), $permitidas) !== []) {
            return [];
        }
        $clave = static fn (mixed $v): bool => is_string($v) && preg_match('/\A[a-z0-9_]{1,40}\z/D', $v) === 1;
        $limpio = [];
        foreach (self::ESTADO_LISTAS as $campo => $maximo) {
            $valor = $estado[$campo] ?? [];
            if (! is_array($valor) || ! array_is_list($valor) || count($valor) > $maximo || array_filter($valor, $clave) !== $valor) {
                return [];
            }
            $limpio[$campo] = $valor;
        }
        foreach (self::ESTADO_CLAVES as $campo => $defecto) {
            $valor = $estado[$campo] ?? $defecto;
            if (! $clave($valor)) {
                return [];
            }
            $limpio[$campo] = $valor;
        }
        $limpio['goal'] = $estado['goal'] ?? 'unknown';
        if (! in_array($limpio['goal'], ['learn', 'service', 'unknown'], true)) {
            return [];
        }
        foreach (self::ESTADO_EDADES as $campo) {
            $valor = $estado[$campo] ?? null;
            if ($valor !== null && (! is_int($valor) || $valor < 0 || $valor > 2)) {
                return [];
            }
            $limpio[$campo] = $valor;
        }
        $foco = $estado['focus_ids'] ?? [];
        if (! is_array($foco) || ! array_is_list($foco) || count($foco) > 2) {
            return [];
        }
        foreach ($foco as $id) {
            $conocido = is_string($id) && preg_match('/\A[a-z][a-z0-9_:.\-]{0,127}\z/D', $id) === 1
                && (str_starts_with($id, 'cat_') ? isset($this->fuentesCatalogo[$id]) : isset(self::conocimiento()['porId'][$id]));
            if (! $conocido) {
                return [];
            }
        }
        $limpio['focus_ids'] = $foco;
        foreach (['out_of_scope', 'quote'] as $campo) {
            $valor = $estado[$campo] ?? false;
            if (! is_bool($valor)) {
                return [];
            }
            $limpio[$campo] = $valor;
        }

        return $limpio;
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
        $result = $this->solicitar('POST', '/api/v1/catalog/validate', $catalogo, $this->ahora());
        if ($result === null || ($result['valid'] ?? null) !== true || ($result['signature'] ?? null) !== 'valid'
            || ($result['loaded'] ?? null) !== false || ($result['errors'] ?? null) !== []) {
            return null;
        }

        return ['valid' => true, 'signature' => 'valid', 'loaded' => false];
    }

    /** @param array<string, mixed> $catalogo
     * @return array{accepted: true, applied: bool, catalog: 'loaded'|'pending', catalog_version: string}|null
     */
    public function sincronizarCatalogo(array $catalogo): ?array
    {
        if (! config('horus-advisor.enabled') || ! config('horus-catalog.sync_enabled', false)) {
            return null;
        }
        $version = $catalogo['catalog_version'] ?? null;
        try {
            if (! is_string($version) || preg_match('/\\A[a-f0-9]{40}\\z/D', $version) !== 1
                || ! app(FirmaCatalogoAdvisor::class)->verificar($catalogo)
                || strlen(json_encode($catalogo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
                return null;
            }
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
        $result = $this->solicitar('POST', '/api/v1/catalog/sync', $catalogo, $this->ahora(), Entrada::decimal(config('horus-catalog.sync_timeout', 25)));
        if ($result === null) {
            return $this->estadoSincronizacionCatalogo($version);
        }
        if (($result['accepted'] ?? null) !== true || ($result['catalog'] ?? null) !== 'loaded'
            || ($result['catalog_version'] ?? null) !== $version || ! is_bool($result['applied'] ?? null)) {
            return null;
        }

        return ['accepted' => true, 'applied' => $result['applied'], 'catalog' => 'loaded', 'catalog_version' => $version];
    }

    /** @return array{accepted: true, applied: bool, catalog: 'loaded'|'pending', catalog_version: string}|null */
    public function estadoSincronizacionCatalogo(string $version): ?array
    {
        if (! config('horus-advisor.enabled') || ! config('horus-catalog.sync_enabled', false)
            || preg_match('/\A[a-f0-9]{40}\z/D', $version) !== 1) {
            return null;
        }
        $estado = $this->solicitar('GET', '/ready', [], $this->ahora());
        if (($estado['auth'] ?? null) !== 'configured' || ($estado['knowledge'] ?? null) !== 'loaded') {
            return null;
        }
        if (($estado['status'] ?? null) === 'ready' && ($estado['catalog'] ?? null) === 'loaded'
            && ($estado['catalog_version'] ?? null) === $version) {
            return ['accepted' => true, 'applied' => false, 'catalog' => 'loaded', 'catalog_version' => $version];
        }
        if (($estado['status'] ?? null) === 'not_ready' && ($estado['catalog'] ?? null) === 'stale'
            && ($estado['catalog_pending_version'] ?? null) === $version) {
            return ['accepted' => true, 'applied' => false, 'catalog' => 'pending', 'catalog_version' => $version];
        }

        return null;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function solicitar(string $metodo, string $ruta, array $payload, float $inicio, ?float $presupuesto = null): ?array
    {
        $url = config('horus-advisor.url');
        $token = config('horus-advisor.token');
        if (! is_string($url) || ! $this->destinoPermitido($url) || ! is_string($token) || strlen($token) < 32
            || preg_match('/[\r\n]/', $token)) {
            $this->causaFallo = 'configuracion';

            return null;
        }
        $limiteTiempo = $presupuesto === null
            ? min(3.0, max(0.1, Entrada::decimal(config('horus-advisor.timeout', 2.5))))
            : min(30.0, max(0.1, $presupuesto));
        $restante = $limiteTiempo - ($this->ahora() - $inicio);
        if ($restante <= 0) {
            $this->causaFallo = 'tiempo_agotado';

            return null;
        }
        $limite = min(65536, max(1024, Entrada::entero(config('horus-advisor.max_response_bytes', 65536))));
        try {
            $response = Http::acceptJson()->withHeaders(['X-Horus-Service-Token' => $token, 'Accept-Encoding' => 'identity'])
                ->connectTimeout(min(0.5, max(0.05, Entrada::decimal(config('horus-advisor.connect_timeout', 0.4))), $restante))
                ->timeout($restante)->withOptions([
                    'allow_redirects' => false, 'proxy' => '', 'cookies' => false, 'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($limite): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limite
                            || ! in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) {
                            throw new RuntimeException(self::MENSAJE_EXCESIVA);
                        }
                    },
                    'progress' => static function (float $total, float $recibidos) use ($limite): void {
                        if ($total > $limite || $recibidos > $limite) {
                            throw new RuntimeException(self::MENSAJE_EXCESIVA);
                        }
                    },
                ])->send($metodo, $url.$ruta, $metodo === 'POST' ? ['json' => $payload] : []);
            if (! $response->successful()) {
                $this->causaFallo = $response->serverError() ? 'http_5xx' : 'http_'.intdiv($response->status(), 100).'xx';

                return null;
            }
            if (strlen($response->body()) > $limite) {
                $this->causaFallo = 'respuesta_excesiva';

                return null;
            }
            if (! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))) {
                $this->causaFallo = 'tipo_contenido';

                return null;
            }
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                $this->causaFallo = 'json_invalido';

                return null;
            }

            return Entrada::mapa($data);
        } catch (Throwable $e) {
            // Nunca registrar excepciones: pueden contener token, cuerpo o configuración. Solo una categoría fija.
            $this->causaFallo = $this->causaDeExcepcion($e);

            return null;
        }
    }

    /** Clasifica sin leer el mensaje de terceros: tipo de excepción y código de error de cURL. */
    private function causaDeExcepcion(Throwable $e): string
    {
        for ($actual = $e; $actual !== null; $actual = $actual->getPrevious()) {
            if ($actual instanceof ConnectException
                && ($actual->getHandlerContext()['errno'] ?? null) === 28) { // CURLE_OPERATION_TIMEDOUT
                return 'tiempo_agotado';
            }
            if ($actual instanceof RuntimeException && $actual->getMessage() === self::MENSAJE_EXCESIVA) {
                return 'respuesta_excesiva';
            }
            if ($actual instanceof \JsonException) {
                return 'json_invalido';
            }
        }

        return $e instanceof ConnectionException ? 'conexion' : 'excepcion';
    }

    /**
     * IP loopback literal (sin DNS) o un hostname exacto de red privada que el operador autorizó.
     * Sin userinfo, ruta, consulta, IP literales ajenas ni hosts de metadatos; las redirecciones y el proxy siguen bloqueados.
     */
    /** `http://127.0.0.1:PUERTO` o `http://<host exacto de HORUS_ADVISOR_ALLOWED_HOSTS>:PUERTO`, nada más. */
    public function destinoPermitido(string $url): bool
    {
        if (preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $url, $match) === 1) {
            return (int) $match[1] <= 65535;
        }
        // La última etiqueta empieza por letra: descarta IP literales como 169.254.169.254.
        if (preg_match('~\Ahttp://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?):([1-9][0-9]{0,4})\z~D', $url, $match) !== 1
            || (int) $match[2] > 65535 || in_array($match[1], self::HOSTS_PROHIBIDOS, true)) {
            return false;
        }
        $permitidos = config('horus-advisor.allowed_hosts', []);

        return is_array($permitidos) && in_array($match[1], $permitidos, true);
    }

    /** Éxito: cierra el circuito. Fallo de transporte: cuenta y, al llegar al umbral, lo abre. */
    private function registrarResultadoCircuito(): void
    {
        if ($this->causaFallo === null) {
            Cache::forget(self::CLAVE_FALLOS);

            return;
        }
        if (! in_array($this->causaFallo, self::CAUSAS_TRANSPORTE, true)) {
            return;
        }
        $segundos = max(1, Entrada::entero(config('horus-advisor.circuit_seconds', 30)));
        Cache::add(self::CLAVE_FALLOS, 0, 2 * $segundos);
        $fallos = Cache::increment(self::CLAVE_FALLOS);
        if (is_int($fallos) && $fallos >= max(1, Entrada::entero(config('horus-advisor.circuit_failures', 3)))) {
            Cache::put(self::CLAVE_ABIERTO, true, $segundos);
            Cache::forget(self::CLAVE_FALLOS);
            Log::warning('chatbot.circuito_abierto', ['segundos' => $segundos, 'causa' => $this->causaFallo]);
        }
    }

    /** @param array<string, mixed> $reply */
    private function respuestaValida(array $reply): bool
    {
        // Procedencia: el texto exacto de un registro verificado, citado con su id y
        // ruta, es contenido institucional local; cualquier alteración lo invalida.
        $answerVerificado = $this->esTextoVerificado($reply);
        if (! $answerVerificado && is_string($reply['answer'] ?? null)
            && isset(self::conocimiento()['porTexto'][$reply['answer']])) {
            return false;
        }
        $evidencia = $this->textoDeFuentes($reply);
        foreach (['answer', 'clarification_question'] as $campo) {
            if (! is_string($reply[$campo] ?? null)) {
                continue;
            }
            $contenido = $reply[$campo];
            if ($campo === 'answer' && $answerVerificado) {
                if (! $this->enlacesDeTextoVerificado($contenido)) {
                    return false;
                }

                continue;
            }
            $sinCanales = str_ireplace(self::CANALES_OFICIALES_PUBLICOS, '', $contenido);
            if (! $this->enlacesValidos($contenido)
                || $this->privacidad->contieneDatosPrivadosEnRespuesta($this->sinEntidadesVerificadas($sinCanales))
                || ! $this->contenidoPermitido($sinCanales, $evidencia)) {
                return false;
            }
        }
        if (array_key_exists('actions', $reply) && $reply['actions'] !== []) {
            // El contrato actual no admite acciones arbitrarias.
            return false;
        }
        if (! is_string($reply['answer'] ?? null) || trim($reply['answer']) === ''
            || mb_strlen($reply['answer']) > min(1800, Entrada::entero(config('chatbot.max_answer_chars', 1800)))
            || ! is_string($reply['intent'] ?? null) || ! preg_match('/\A[a-z_]{1,64}\z/D', $reply['intent'])
            || ! in_array($reply['mode'] ?? null, ['lexical', 'hybrid'], true)
            || ! in_array($reply['match_level'] ?? null, ['alta', 'media', 'baja', 'ninguna'], true)
            || ! is_bool($reply['abstained'] ?? null)
            || ! is_bool($reply['needs_clarification'] ?? null)
            || ! is_array($reply['sources'] ?? null) || ! array_is_list($reply['sources'])
            || count($reply['sources']) > 10
            || ! is_array($reply['recommendations'] ?? null) || ! array_is_list($reply['recommendations'])
            || count($reply['recommendations']) > 5) {
            return false;
        }

        // Si requiere aclaración, clarification_question debe ser una pregunta válida y segura
        if ($reply['needs_clarification']) {
            $pregunta = $reply['clarification_question'] ?? null;
            if (! is_string($pregunta) || trim($pregunta) === '' || mb_strlen($pregunta) > 500) {
                return false;
            }
            if (mb_strlen($reply['answer']."\n\n".$pregunta) > min(1800, Entrada::entero(config('chatbot.max_answer_chars', 1800)))) {
                return false;
            }
            $preguntaLimpia = str_ireplace(self::CANALES_OFICIALES_PUBLICOS, '', $pregunta);
            if (preg_match('~<|>|@|https?://|\d[\d\s-]{6,}\d|\b(?:token|traceback|password|api_key)\b|\b(?:s/\.?|usd|us\$)\s*\d|\b\d+(?:[.,]\d+)?\s*(?:soles|dolares|dólares)\b|\b\d{1,2}[/\-]\d{1,2}[/\-]\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b~iu', $preguntaLimpia)) {
                return false;
            }
        }

        // Filtro de seguridad de la respuesta:
        // No publicar HTML, enlaces arbitrarios, precios/fechas sin evidencia, PII o secretos de una respuesta defectuosa.
        // Se permiten únicamente los canales oficiales públicos exactos y verificados de Horus Group en la salida del asistente.
        $texto = str_ireplace(self::CANALES_OFICIALES_PUBLICOS, '', $reply['answer']);
        if (! $answerVerificado && preg_match('~<|>|@|https?://|\d[\d\s-]{6,}\d|\b(?:token|traceback|password|api_key)\b|\b(?:s/\.?|usd|us\$)\s*\d|\b\d+(?:[.,]\d+)?\s*(?:soles|dolares|dólares)\b|\b\d{1,2}[/\-]\d{1,2}[/\-]\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b~iu', $texto)) {
            return false;
        }

        // Intenciones de cortesía/capacidades no requieren fuentes
        $esPlantillaCortesia = in_array($reply['intent'], ['greeting', 'thanks', 'farewell', 'capabilities'], true);
        if ($esPlantillaCortesia) {
            return $reply['sources'] === [] && $reply['recommendations'] === [] && ! $reply['abstained'] && ! $reply['needs_clarification'];
        }

        // Aclaraciones y abstenciones honestas admitidas
        $esAclaracion = $reply['needs_clarification'] && in_array($reply['intent'], ['clarification', 'quote_request', 'course_recommendation', 'service_recommendation'], true);
        $esAbstencionHonesta = $reply['abstained'] && in_array($reply['intent'], ['unavailable_info', 'unverified_info', 'out_of_scope', 'quote_request', 'course_recommendation', 'service_recommendation'], true);
        if ($reply['intent'] === 'out_of_scope' && (! $reply['abstained'] || $reply['needs_clarification']
            || $reply['sources'] !== [] || $reply['recommendations'] !== [] || $reply['match_level'] !== 'ninguna')) {
            return false;
        }

        if ($reply['abstained'] && ! $esAbstencionHonesta) {
            return false;
        }

        if ($reply['needs_clarification'] && ! $esAclaracion) {
            return false;
        }

        // Respuestas afirmativas que no son aclaraciones ni abstenciones requieren al menos una fuente verificada y nivel alta/media
        if (! $esAclaracion && ! $esAbstencionHonesta) {
            if ($reply['sources'] === [] || ! in_array($reply['match_level'], ['alta', 'media'], true)) {
                return false;
            }
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
                || ! is_string($recomendacion['kind'] ?? null)
                || ! in_array($recomendacion['kind'], ['course', 'training_program', 'service', 'advisory', 'agreement', 'course_modality'], true)
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

    private function enlacesValidos(string $texto): bool
    {
        if (preg_match('~\b(?:env[ií]a(?:me|nos)?|indica|dame|comparte|adjunta|proporciona|escribe)\b[^.!?]{0,160}\b(?:dni|ruc|celular|tel[eé]fono|comprobante|voucher|contrase[ñn]a|datos personales)\b|\b(?:cuesta|precio es|costo es|quedan\s+\w+\s+cupos|horario es de)\b~iu', $texto)) {
            // Fechas, cifras y montos se evalúan contra la evidencia en contenidoPermitido().
            return false;
        }
        if (preg_match('~\b\d{1,3}(?:\.\d{1,3}){3}\b|[。．｡]~u', $texto)) {
            return false;
        }
        $texto = preg_replace('~(?<![\w.@])horusgroupcajamarca@gmail\.com(?![\w@]|\.[a-z0-9])~u', '', $texto) ?? '';
        preg_match_all('~(?:[a-z][a-z0-9+.-]*:|//|www\.)[^\s<>]+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}(?:/[^\s<>]*)?~iu', $texto, $matches);
        foreach ($matches[0] as $token) {
            $url = rtrim($token, '.,;!)]}');
            if (! in_array($url, array_slice(self::CANALES_OFICIALES_PUBLICOS, 0, 3), true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $source */
    private function referenciaValida(array $source): bool
    {
        $editorial = is_string($source['id'] ?? null) ? ($this->fuentesCatalogo[$source['id']] ?? null) : null;
        if ($editorial !== null) {
            return ($source['title'] ?? null) === $editorial['title']
                && array_key_exists('url_path', $source) && $source['url_path'] === $editorial['url_path'];
        }

        return is_string($source['id'] ?? null) && (bool) preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $source['id'])
            && ! str_starts_with($source['id'], 'cat_')
            && is_string($source['title'] ?? null) && trim($source['title']) !== '' && mb_strlen($source['title']) <= 200
            && in_array($source['url_path'] ?? null, self::RUTAS_PUBLICAS, true);
    }

    private function esBloqueadoPorSeguridad(string $texto): bool
    {
        // Sondas de inyección de código sobre el texto original (plantillas, JNDI, HTML activo, SQL): no son temas
        // institucionales y no necesitan viajar a Python. Mismo criterio que app/core/security.py.
        if (preg_match('/\$\{[^}]{0,200}\}|[#{]\{[^}]{0,200}\}|\bjndi\s*:|<\s*(?:script|img|iframe|svg|object|embed)\b|\bon(?:error|load|click|mouseover)\s*='
            ."|'\\s*(?:or|and)\\s+'?\\d+'?\\s*=\\s*'?\\d+|'\\s*;|;\\s*--|\\bunion\\s+(?:all\\s+)?select\\b/iu", $texto) === 1) {
            return true;
        }
        $normalizado = strtr(mb_strtolower($texto, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        // Patrones de ataque, no palabras sueltas: «instrucciones», «prompt», «system», «contraseña», «olvida» o «revela»
        // aparecen en consultas legítimas («¿cómo recupero mi contraseña del aula virtual?», «si mi hijo olvida una
        // clase…»). Medición del 22/11/2026: las palabras sueltas bloqueaban 13 de 18 consultas legítimas; los patrones,
        // ninguna, y detienen los 22 ataques del corpus (HorusSeguridadPatronesTest). Los datos personales los cubre
        // el filtro de privacidad, y toda respuesta sigue pasando por la validación de procedencia.
        return (bool) preg_match('/\b(?:ignora|ignore|ignoren|olvida|olvidate|olviden|override|omite|omitan)\b.{0,40}'
            .'\b(?:instrucciones?|indicaciones|reglas|restricciones|lo anterior|todo|prompt|instructions?|rules|safety|seguridad|filtros?)\b'
            .'|\b(?:finge|finjas|pretend|jailbreak|inventa|inventate)\b'
            .'|\b(?:dame|dime|muestra\w*|revela\w*|pasa(?:me|nos)|comparte\w*|extrae\w*|imprime|filtra\w*|accede\w*|cual(?:es)?\s+(?:es|son))\b.{0,60}'
            .'\b(?:contrasenas?|passwords?|credenciales|tokens?|api\s*keys?|secretos?)\b'
            .'|\bsystem\s*(?:prompt|message|:)|\bprompt\s+(?:del\s+)?sistema\b|\b(?:tu|tus|su|sus)\s+(?:prompt|instrucciones|instruccion|reglas)\b'
            .'|\b(?:tu|tus)\s+(?:contrasena|password|credenciales|token|configuracion)\b'
            .'|\binstrucciones\s+(?:del\s+sistema|internas|ocultas|previas|anteriores|originales|iniciales|secretas)\b|\bmodo\s+(?:desarrollador|developer|dios|sin\s+restricciones)\b'
            .'|\b(?:accede\w*|muestra\w*|dame|dime|extrae\w*|lista\w*|revela\w*|pasa(?:me|nos)|comparte\w*|filtra\w*)\b.{0,60}'
            .'\b(?:panel administrativo|notas de|(?:dni|datos|nombres|telefonos|correos|direcciones|notas)\s+de\s+(?:los\s+|las\s+)?(?:estudiantes|alumnos|matriculados|inscritos|clientes)'
            .'|matriculados|estudiantes inscritos)\b/u', $normalizado);
    }

    private const NUMERALES = '(?:dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieci\w+|veinte|veinti\w+|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento|doscientos|trescientos|cuatrocientos|quinientos|seiscientos|setecientos|ochocientos|novecientos|mil|millones?)';

    private const CALENDARIO = '(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bados?|domingos?|pr[oó]xim[oa]s?|ma[ñn]ana)';

    /**
     * Texto no verificado: toda cifra, numeral o referencia de calendario debe constar en
     * los registros locales citados como fuente; montos, disponibilidad y peticiones de
     * datos personales se rechazan siempre. No se enumeran formatos prohibidos.
     */
    private function contenidoPermitido(string $texto, string $evidencia): bool
    {
        $evidenciaNormalizada = $this->normalizar($evidencia);
        $normalizado = $this->normalizar($texto);
        if (preg_match('~[$€£¥]|\bs\s*/|\b(?:usd|us\$|eur|soles?|d[oó]lares?|euros?|lucas)\b~iu', $texto)
            || preg_match('~\bPEN\b~u', $texto)) {
            return false;
        }
        // Los marcadores de lista («1.», «2.») no son cifras afirmadas.
        preg_match_all('~\d+~', preg_replace('~^\s*\d{1,2}\.\s~mu', '', $texto) ?? $texto, $cifras);
        preg_match_all('~\d+~', $evidencia, $cifrasEvidencia);
        foreach ($cifras[0] as $cifra) {
            if (! in_array($cifra, $cifrasEvidencia[0], true)) {
                return false;
            }
        }
        preg_match_all('~\b(?:'.self::NUMERALES.'|'.self::CALENDARIO.')\b~u', $normalizado, $palabras);
        foreach ($palabras[0] as $palabra) {
            if (! preg_match('~\b'.preg_quote($palabra, '~').'\b~u', $evidenciaNormalizada)) {
                return false;
            }
        }
        // Afirmar disponibilidad exige un sistema de cupos que no existe; negarla
        // («no tengo información sobre cupos») es una abstención honesta.
        foreach (preg_split('/[.!?¿¡;\n]+/u', $normalizado) ?: [] as $frase) {
            if (preg_match('~\b(?:cupos?|vacantes?|plazas?)\b~u', $frase)
                && preg_match('~\b(?:quedan?|hay|solo|ultim\w*|pocos?|pocas|disponibles?|agotad\w*|limitad\w*)\b~u', $frase)
                && ! preg_match('~\b(?:no|sin|ningun\w*|tampoco)\b~u', $frase)) {
                return false;
            }
        }

        $sensible = '(?:dni|ruc|celular|telefono|(?:tu|su)\s+numero(?!\s+de\s+(?:pedido|orden))|numero\s+de\s+(?:celular|telefono|dni|documento|contacto|whatsapp|cuenta|tarjeta|identidad)|nombres?(?:\s+completos?)?|apellidos?|distrito|direccion|domicilio|correo|e-?mail|fecha\s+de\s+nacimiento|edad'
            .'|comprobante|voucher|contrasena|credenciales|datos\s+personales|cuenta\s+bancaria)';
        $verbos = '(?:manda\w*|envia\w*|envie\w*|compart\w*|indica\w*|indique\w*|proporcion\w*|adjunt\w*|escrib\w*|dame|dime|da(?:s|me|nos)|pasa\w*|deja\w*'
            .'|confirma\w*|sube\w*|subi\w*|facilita\w*|responde\w*|registra\w*|ingresa\w*|digita\w*|anota\w*|necesito|necesitamos|requiero|requerimos|pide\w*|pido|solicit\w*)';
        foreach (preg_split('/[.!?¿¡;\n]+/u', $normalizado) ?: [] as $frase) {
            if (! preg_match('~\b'.$sensible.'\b~u', $frase)) {
                continue;
            }
            $aviso = preg_match('~\b(?:no|nunca|jamas)\s+(?:\w+\s+){0,2}(?:compartas|envies|proporciones|escribas|pediremos|pedimos|solicitamos|solicitaremos|incluyas|dejes)\b~u', $frase);
            // Derivación explícita a personal humano, nunca recolección en el chat.
            $externo = preg_match('~\b(?:por whatsapp|por ese medio|con (?:un )?asesor|al personal|directamente)\b~u', $frase)
                && ! preg_match('~\b(?:aqui|este chat|por el chat|en el chat)\b~u', $frase);
            $peticion = preg_match('~\b'.$verbos.'\b[^.!?]{0,160}\b'.$sensible.'\b~u', $frase)
                || preg_match('~\bcual(?:es)?\s+(?:es|son)\b[^.!?]{0,40}\b'.$sensible.'\b~u', $frase)
                || preg_match('~\ben\s+que\s+distrito\s+(?:vives|resides|estas)\b~u', $frase);
            if ($peticion && ! $aviso && ! $externo) {
                return false;
            }
        }

        return true;
    }

    private function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /** Texto verificado: solo se exige ausencia de HTML y de enlaces ajenos a los canales oficiales. */
    private function enlacesDeTextoVerificado(string $texto): bool
    {
        $texto = str_ireplace(array_slice(self::CANALES_OFICIALES_PUBLICOS, 0, 3), '', $texto);

        return ! preg_match('~[<>]|\b[a-z][a-z0-9+.-]*://|\bwww\.~iu', $texto);
    }

    /** @param array<string, mixed> $reply */
    private function esTextoVerificado(array $reply): bool
    {
        if (! is_string($reply['answer'] ?? null) || ! is_array($reply['sources'] ?? null)) {
            return false;
        }
        $fragmentosCitados = [];
        foreach ($reply['sources'] as $source) {
            $editorial = is_array($source) && is_string($source['id'] ?? null) ? ($this->fuentesCatalogo[$source['id']] ?? null) : null;
            if ($editorial !== null && $this->referenciaValida($source)) {
                if ($reply['answer'] === $editorial['answer']
                    || in_array($reply['answer'], $editorial['fragments'] ?? [], true)) {
                    return true;
                }
                array_push($fragmentosCitados, ...($editorial['fragments'] ?? []));
            }
        }
        $lineas = explode("\n", $reply['answer']);
        if (count($lineas) >= 2 && count($lineas) <= 10 && $fragmentosCitados !== []
            && count(array_filter($lineas, static fn (string $linea): bool => in_array($linea, $fragmentosCitados, true))) === count($lineas)) {
            return true;
        }
        $conocimiento = self::conocimiento();
        foreach ($conocimiento['porTexto'][$reply['answer']] ?? [] as $origen) {
            foreach ($reply['sources'] as $source) {
                if (is_array($source) && ($source['id'] ?? null) === $origen['id'] && ($source['url_path'] ?? null) === $origen['url_path']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $reply */
    private function textoDeFuentes(array $reply): string
    {
        $conocimiento = self::conocimiento();
        $textos = [];
        foreach (is_array($reply['sources'] ?? null) ? $reply['sources'] : [] as $source) {
            // Fichas publicadas vigentes citadas con su título y ruta exactos: su texto es evidencia (p. ej. la duración
            // de dos cursos en una misma respuesta de seguimiento).
            $editorial = is_array($source) && is_string($source['id'] ?? null) ? ($this->fuentesCatalogo[$source['id']] ?? null) : null;
            if ($editorial !== null && $this->referenciaValida($source)) {
                $textos[] = implode("\n", [$editorial['title'], $editorial['answer'], ...($editorial['fragments'] ?? [])]);

                continue;
            }
            $registro = is_array($source) && is_string($source['id'] ?? null) ? ($conocimiento['porId'][$source['id']] ?? null) : null;
            if ($registro !== null && ($source['url_path'] ?? null) === $registro['url_path']) {
                $textos[] = $registro['texto'];
            }
        }

        return implode("\n", $textos);
    }

    /** Retira entidades públicas presentes en registros verificados antes de buscar nombres personales. */
    private function sinEntidadesVerificadas(string $texto): string
    {
        $entidades = self::conocimiento()['entidades'];

        return $entidades === [] ? $texto : str_ireplace($entidades, ' institución ', $texto);
    }

    /**
     * Conocimiento institucional local, cacheado por proceso e invalidado al cambiar el archivo.
     *
     * @return array{porTexto: array<string, list<array{id: string, url_path: string|null}>>, porId: array<string, array{title: string, texto: string, url_path: string|null}>, entidades: list<string>}
     */
    private static function conocimiento(): array
    {
        /** @var array{firma: string, datos: array{porTexto: array<string, list<array{id: string, url_path: string|null}>>, porId: array<string, array{title: string, texto: string, url_path: string|null}>, entidades: list<string>}}|null $cache */
        static $cache = null;
        $vacio = ['porTexto' => [], 'porId' => [], 'entidades' => []];
        $path = Entrada::texto(config('horus-knowledge.path'));
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path)) {
            return $vacio;
        }
        $firma = $path.'|'.filemtime($path).':'.filesize($path);
        if (is_array($cache) && $cache['firma'] === $firma) {
            return $cache['datos'];
        }
        $knowledge = json_decode((string) file_get_contents($path), true);
        $datos = $vacio;
        $entidades = [];
        foreach (is_array($knowledge) && is_array($knowledge['records'] ?? null) ? $knowledge['records'] : [] as $record) {
            if (! is_array($record) || ($record['status'] ?? null) !== 'verified' || ! is_string($record['id'] ?? null)
                || ! is_string($record['answer'] ?? null)) {
                continue;
            }
            $fuente = $record['source'] ?? null;
            $url = is_array($fuente) && is_string($fuente['url_path'] ?? null) ? $fuente['url_path'] : null;
            $respuestas = [$record['answer']];
            if (is_string($record['general_information'] ?? null)) {
                $respuestas[] = $record['general_information'];
            }
            if ($record['id'] === 'convenios_resumen') {
                $respuestas[] = app(ConveniosPublicos::class)->listado();
            }
            if (is_string($record['detail_answer'] ?? null)) {
                $respuestas[] = $record['detail_answer'];
            }
            // Frases exactas del texto verificado (respuesta y detalle): el motor puede responder solo con la que aclara la consulta.
            foreach ([$record['answer'], $record['detail_answer'] ?? null] as $texto) {
                foreach (is_string($texto) ? (preg_split('/(?<=[.;])\s+/u', $texto) ?: []) : [] as $frase) {
                    $frase = trim($frase);
                    if (mb_strlen($frase) >= 10 && mb_strlen($frase) <= 600) {
                        $respuestas[] = $frase;
                    }
                }
            }
            $tarjeta = $record['public_card'] ?? null;
            if (is_array($tarjeta) && isset($tarjeta['vigencia']) && is_string($record['title'] ?? null)) {
                $respuestas[] = $record['title'].': el convenio está vigente hasta el '.Entrada::texto($tarjeta['vigencia']).'.';
            }
            foreach ($respuestas as $respuesta) {
                $datos['porTexto'][$respuesta][] = ['id' => $record['id'], 'url_path' => $url];
            }
            $texto = implode("\n", array_filter([$record['title'] ?? null, $record['description'] ?? null, ...$respuestas], 'is_string'));
            $datos['porId'][$record['id']] = ['title' => Entrada::texto(($record['title'] ?? '')), 'texto' => $texto, 'url_path' => $url];
            preg_match_all('/\b[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]{1,25}(?:(?:\s+(?:de|del|la|las|los|y|san|santa))?[\s\-]+[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]{1,25})+\b/u', $texto, $coincidencias);
            array_push($entidades, ...$coincidencias[0]);
        }
        $entidades = array_values(array_unique($entidades));
        usort($entidades, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $datos['entidades'] = $entidades;
        $cache = ['firma' => $firma, 'datos' => $datos];

        return $datos;
    }
}
