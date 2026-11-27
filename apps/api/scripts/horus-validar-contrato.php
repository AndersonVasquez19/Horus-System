<?php

declare(strict_types=1);

/**
 * Validador de contrato Python ↔ Laravel en modo solo lectura (sin base de datos ni modificaciones).
 *
 * Lee JSON por STDIN:
 *   {"items": [...catalogo...], "cases": [{"query": "...", "reply": {...}}, ...]}
 * Imprime JSON por STDOUT:
 *   [{"privacy": string|null, "security": bool, "reply_valid": bool, "state_valid": bool}, ...]
 */

require __DIR__.'/../vendor/autoload.php';

use App\Services\ConveniosPublicos;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\FuentesCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use App\Support\FiltroPrivacidadChat;

$raw = stream_get_contents(STDIN);
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (! is_array($payload) || ! is_array($payload['cases'] ?? null)) {
    fwrite(STDERR, "Entrada JSON invalida.\n");
    exit(1);
}

$knowledgePath = realpath(__DIR__.'/../../ai/data/knowledge.json');
if ($knowledgePath === false || ! is_file($knowledgePath)) {
    fwrite(STDERR, "No se encontro apps/ai/data/knowledge.json.\n");
    exit(1);
}

$knowledgeConfigPath = $knowledgePath;
if (! is_readable($knowledgePath)) {
    // En sandbox de Windows PHP is_readable() devuelve false aunque file_get_contents() lea el archivo.
    $bytes = (string) file_get_contents($knowledgePath);
    class HorusContratoMemStream
    {
        public static string $content = '';

        private int $pos = 0;

        /** @var resource|null */
        public $context;

        public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
        {
            $this->pos = 0;

            return true;
        }

        public function stream_read(int $count): string
        {
            $chunk = substr(self::$content, $this->pos, $count);
            $this->pos += strlen($chunk);

            return $chunk;
        }

        public function stream_eof(): bool
        {
            return $this->pos >= strlen(self::$content);
        }

        /** @return array<int|string, int> */
        public function stream_stat(): array
        {
            return $this->url_stat('', 0);
        }

        /** @return array<int|string, int> */
        public function url_stat(string $path, int $flags): array
        {
            $size = strlen(self::$content);
            $mode = 0100444;

            return [
                0 => 0, 1 => 0, 2 => $mode, 3 => 1, 4 => 0, 5 => 0, 6 => 0, 7 => $size,
                8 => 1700000000, 9 => 1700000000, 10 => 1700000000, 11 => -1, 12 => -1,
                'dev' => 0, 'ino' => 0, 'mode' => $mode, 'nlink' => 1, 'uid' => 0, 'gid' => 0,
                'rdev' => 0, 'size' => $size, 'atime' => 1700000000, 'mtime' => 1700000000,
                'ctime' => 1700000000, 'blksize' => -1, 'blocks' => -1,
            ];
        }
    }
    HorusContratoMemStream::$content = $bytes;
    if (! in_array('horuscontrato', stream_get_wrappers(), true)) {
        stream_wrapper_register('horuscontrato', HorusContratoMemStream::class);
    }
    $knowledgeConfigPath = 'horuscontrato://knowledge.json';
}

$signingKey = str_repeat('k', 32);
$app = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository([
    'horus-knowledge' => ['path' => $knowledgeConfigPath],
    'chatbot' => ['max_answer_chars' => 1800],
    'horus-advisor' => ['enabled' => true, 'signing_key' => $signingKey],
    'horus-catalog' => ['sync_enabled' => true, 'expected_version_cache_key' => 'horus-ai.catalog.expected-version'],
]));
$cacheStore = new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore);
$app->instance('cache', $cacheStore);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$firma = new FirmaCatalogoAdvisor;
$app->instance(FirmaCatalogoAdvisor::class, $firma);
$contenido = ['managed_types' => ['curso', 'servicio'], 'items' => $items];
$catalogo = [
    'schema_version' => '1.0',
    'catalog_version' => $firma->versionContenido($contenido),
    'source_system' => 'horus-laravel',
    'generated_at' => gmdate('c'),
    'expires_at' => gmdate('c', time() + 600),
    ...$contenido,
];
$catalogo['signature'] = $firma->firmar($catalogo);
$cacheStore->forever('horus-ai.catalog.expected-version', $catalogo['catalog_version']);

$app->singleton(ConveniosPublicos::class, static fn () => new ConveniosPublicos);
$app->instance(ExportadorCatalogoAdvisor::class, new class($catalogo) extends ExportadorCatalogoAdvisor
{
    /** @param array<string, mixed> $cat */
    public function __construct(private array $cat) {}

    public function construirParaSincronizacion(): array
    {
        return $this->cat;
    }
});

// Usa la clase real FuentesCatalogoAdvisor (paridad 100 % con producción, sin duplicar reglas).
$fuentes = (new FuentesCatalogoAdvisor)->actuales();
$app->instance(FuentesCatalogoAdvisor::class, new class($fuentes) extends FuentesCatalogoAdvisor
{
    /** @param array<string, array{title: string, url_path: string|null, answer: string, fragments?: list<string>}> $f */
    public function __construct(private array $f) {}

    public function actuales(): array
    {
        return $this->f;
    }
});

$filtro = new FiltroPrivacidadChat;
$cliente = new HorusAdvisorClient($filtro);
$propFuentes = new ReflectionProperty(HorusAdvisorClient::class, 'fuentesCatalogo');
$propFuentes->setValue($cliente, $fuentes);
$mSeguridad = new ReflectionMethod(HorusAdvisorClient::class, 'esBloqueadoPorSeguridad');
$mRespuesta = new ReflectionMethod(HorusAdvisorClient::class, 'respuestaValida');
$mEstado = new ReflectionMethod(HorusAdvisorClient::class, 'estadoValido');

$resultados = [];
foreach ($payload['cases'] as $caso) {
    $query = (string) ($caso['query'] ?? '');
    $reply = is_array($caso['reply'] ?? null) ? $caso['reply'] : null;
    $privacy = $filtro->motivoBloqueo($query);
    $security = (bool) $mSeguridad->invoke($cliente, $query);
    $replyValid = $reply === null ? true : (bool) $mRespuesta->invoke($cliente, $reply);
    $state = is_array($reply) ? ($reply['state'] ?? null) : null;
    $stateValid = true;
    if (is_array($state)) {
        $limpio = $mEstado->invoke($cliente, $state);
        $stateValid = is_array($limpio) && $limpio !== [];
    }
    $resultados[] = [
        'privacy' => $privacy,
        'security' => $security,
        'reply_valid' => $replyValid,
        'state_valid' => $stateValid,
    ];
}

echo json_encode($resultados, JSON_UNESCAPED_UNICODE);
