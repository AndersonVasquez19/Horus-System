<?php

declare(strict_types=1);

use App\Services\AsistenteVirtualService;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\OperacionHorus;
use Dotenv\Dotenv;
use Horus\Local\LocalCatalogRenewal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Support/LocalCatalogRenewal.php';

$root = dirname(__DIR__);
$action = $argv[1] ?? '';
$runtime = isset($argv[2]) && str_starts_with($argv[2], '--runtime=') ? substr($argv[2], 10) : '';
if (! in_array($action, ['configure', 'run', 'inspect', 'verify'], true) || ! is_dir($runtime)) {
    fwrite(STDERR, "Uso: php scripts/horus-local.php configure|run|inspect|verify --runtime=<directorio privado existente>\n");
    exit(1);
}
$children = [];
$handles = [];
$exitCode = 0;

function requireLocal(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function updateLocalEnv(string $path, array $updates, string $runtime): void
{
    $before = file_get_contents($path);
    $hash = hash('sha256', $before);
    $eol = str_contains($before, "\r\n") ? "\r\n" : "\n";
    $text = $before;
    foreach ($updates as $key => $value) {
        requireLocal((bool) preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $key), 'Nombre de configuración inválido.');
        requireLocal(! preg_match('/[\r\n$"\\\\]/', $value), 'Valor de configuración no compatible.');
        $line = $key.'="'.$value.'"';
        $pattern = '/^(?:export\h+)?'.preg_quote($key, '/').'\h*=.*$/m';
        requireLocal(preg_match_all($pattern, $text) <= 1, 'Variable duplicada: '.$key);
        if (preg_match($pattern, $text)) {
            $text = preg_replace_callback($pattern, static fn (): string => $line, $text);
        } else {
            $text = rtrim($text, "\r\n").$eol.$line.$eol;
        }
    }
    Dotenv::parse($text);
    requireLocal(hash_equals($hash, hash_file('sha256', $path)), 'El entorno cambió en paralelo; no se sobrescribe.');
    $temporary = tempnam($runtime, 'profile-');
    requireLocal($temporary !== false && file_put_contents($temporary, $text, LOCK_EX) === strlen($text), 'No se pudo preparar el perfil.');
    requireLocal(rename($temporary, $path), 'No se pudo instalar el perfil; temporal privado conservado.');
}

function cleanLocalEnv(): array
{
    $env = ['NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost'];
    foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $name) {
        $env[$name] = false;
    }

    return $env;
}

function operationalEnv(): array
{
    return cleanLocalEnv() + ['HORUS_ADVISOR_ENABLED' => 'true', 'APP_DEBUG' => 'false',
        'HORUS_PG_TEST_PROFILE' => false, 'HORUS_PG_TEST_CONFIRM' => false];
}

function runLocal(array $command, string $root, array $env, float $timeout = 30, bool $allowNotReady = false): string
{
    $process = new Process($command, $root, $env);
    $process->setTimeout($timeout);
    $exit = $process->run();
    requireLocal($exit === 0 || ($allowNotReady && $exit === 1), 'El comando local falló; detalles privados omitidos.');

    return $process->getOutput();
}

function localReady(): array
{
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true, 'follow_location' => 0]]);
    $raw = @file_get_contents('http://127.0.0.1:18081/ready', false, $context, 0, 4097);
    $data = $raw === false ? null : json_decode($raw, true);

    return is_array($data) ? $data : [];
}

try {
    requireLocal(str_starts_with(str_replace('\\', '/', realpath($runtime)), str_replace('\\', '/', getenv('LOCALAPPDATA')).'/HorusAI/'), 'Directorio de operación fuera de la raíz privada.');
    $rootEnv = $root.'/.env';
    $pythonEnv = $root.'/chatbot-python/.env';
    $php = Dotenv::parse(file_get_contents($rootEnv));
    $python = Dotenv::parse(file_get_contents($pythonEnv));
    foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'horus_group'] as $key => $value) {
        requireLocal(($php[$key] ?? null) === $value, 'Destino local distinto del autorizado.');
    }
    requireLocal(! file_exists($root.'/bootstrap/cache/config.php'), 'Configuración cacheada: no se elimina automáticamente.');
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=horus_group;options='-c default_transaction_read_only=on'",
        $php['DB_USERNAME'], $php['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $id = $pdo->query('SELECT current_database() AS database,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS physical')->fetch(PDO::FETCH_ASSOC);
    requireLocal($id['database'] === 'horus_group' && (int) $id['port'] === 5432 && $id['physical'] === '7701086120026667920', 'Identidad física incompatible.');
    if ($action === 'configure') {
        requireLocal(! file_exists($runtime.'/root.env.before') && ! file_exists($runtime.'/python.env.before'), 'Ya existe un respaldo de perfiles; no se sobrescribe.');
        requireLocal(copy($rootEnv, $runtime.'/root.env.before') && copy($pythonEnv, $runtime.'/python.env.before'), 'Falló el respaldo privado de perfiles.');
        $token = $python['HORUS_AI_SERVICE_TOKEN'] ?? '';
        if (! preg_match('/\A[A-Za-z0-9_-]{32,}\z/', $token)) {
            $token = bin2hex(random_bytes(32));
        }
        $signing = $python['HORUS_AI_CATALOG_SIGNING_KEY'] ?? '';
        if (! preg_match('/\A[A-Za-z0-9_-]{32,}\z/', $signing)) {
            $signing = bin2hex(random_bytes(32));
        }
        requireLocal(is_dir($runtime.'/vectors') || mkdir($runtime.'/vectors'), 'Directorio de caché no disponible.');
        updateLocalEnv($pythonEnv, ['HORUS_AI_HOST' => '127.0.0.1', 'HORUS_AI_PORT' => '18081',
            'HORUS_AI_ALLOW_NON_LOOPBACK' => 'false', 'HORUS_AI_SERVICE_TOKEN' => $token,
            'HORUS_AI_CATALOG_SIGNING_KEY' => $signing, 'HORUS_AI_CATALOG_PATH' => str_replace('\\', '/', $runtime).'/catalog.json',
            'HORUS_AI_CATALOG_ALLOW_UNSIGNED' => 'false', 'HORUS_AI_SEMANTIC_ENABLED' => 'true',
            'HORUS_AI_SEMANTIC_CACHE_DIR' => str_replace('\\', '/', $runtime).'/vectors', 'HORUS_OPS_ENABLED' => 'true'], $runtime);
        updateLocalEnv($rootEnv, ['HORUS_ADVISOR_ENABLED' => 'false', 'HORUS_ADVISOR_URL' => 'http://127.0.0.1:18081',
            'HORUS_ADVISOR_TOKEN' => $token, 'HORUS_ADVISOR_SIGNING_KEY' => $signing,
            'HORUS_AI_CATALOG_SYNC_ENABLED' => 'true', 'HORUS_OPS_ENABLED' => 'true', 'HORUS_OPS_RENEW_CATALOG' => 'true'], $runtime);
        file_put_contents($runtime.'/profiles.json', json_encode(['root_sha256' => hash_file('sha256', $rootEnv),
            'python_sha256' => hash_file('sha256', $pythonEnv), 'advisor_enabled' => false], JSON_THROW_ON_ERROR), LOCK_EX);
        echo "Perfiles locales configurados; credenciales concordantes; chatbot todavía apagado.\n";
        exit(0);
    }
    if (in_array($action, ['inspect', 'verify'], true)) {
        putenv('HORUS_ADVISOR_ENABLED=true');
        $_ENV['HORUS_ADVISOR_ENABLED'] = $_SERVER['HORUS_ADVISOR_ENABLED'] = 'true';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        requireLocal(config('matricula-mail.enabled') === false && config('registro-verificacion.enabled') === false, 'Hay una activación de correo no contemplada.');
        $snapshot = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        if ($action === 'verify') {
            requireLocal(($php['HORUS_ADVISOR_ENABLED'] ?? '') === 'true', 'El perfil web todavía no está habilitado.');
            $status = app(OperacionHorus::class)->estado(true, true);
            requireLocal($status['ready'] === true, 'Preflight operativo no aprobado.');
            $requests = 0;
            Http::globalRequestMiddleware(static function ($request) use (&$requests) {
                requireLocal($request->getUri()->getHost() === '127.0.0.1' && $request->getUri()->getPort() === 18081, 'Destino HTTP fuera del servicio local.');
                $requests++;

                return $request;
            });
            $service = app(AsistenteVirtualService::class);
            $session = 'verificacion-local-'.bin2hex(random_bytes(8));
            $reply = $service->procesarMensaje('Hola', $session);
            requireLocal($reply['fuente'] === 'advisor' && $requests >= 2, 'El chat no recorrió FastAPI real.');
            $before = $requests;
            $private = $service->procesarMensaje('Mi DNI es 12345678', $session.'-privacidad');
            requireLocal($private['fuente'] === 'respaldo' && $requests === $before, 'La barrera de privacidad no detuvo el HTTP.');
            $hash = hash_hmac('sha256', 'direct|'.$session.'-privacidad', (string) config('app.key'));
            $audit = DB::table('chat_logs')->where('session_id', $hash)->first(['mensaje_usuario', 'fuente']);
            requireLocal($audit !== null && $audit->mensaje_usuario === '[contenido omitido por privacidad]' && $audit->fuente === 'respaldo', 'Auditoría privada no redactada.');
            config(['horus-advisor.enabled' => false]);
            requireLocal($service->procesarMensaje('cursos', $session.'-contingencia')['fuente'] === 'respaldo', 'La contingencia no funciona.');
            echo json_encode(['verified' => true, 'real_advisor' => true, 'privacy_http_requests' => 0,
                'audit_redacted' => true, 'contingency' => true, 'published_items' => count($snapshot['items']), 'operation' => $status], JSON_THROW_ON_ERROR).PHP_EOL;
            exit(0);
        }
        echo json_encode(['items' => count($snapshot['items']), 'types' => array_count_values(array_column($snapshot['items'], 'type')),
            'version' => $snapshot['catalog_version'], 'pending_jobs' => DB::table('jobs')->count(),
            'pending_migrations' => array_values(array_diff(array_map(static fn (string $p): string => basename($p, '.php'), glob($root.'/database/migrations/*.php')),
                DB::table('migrations')->pluck('migration')->all()))], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    requireLocal($pdo->query('SELECT count(*) FROM public.jobs')->fetchColumn() == 0, 'La cola contiene trabajos previos; no se inicia un consumidor.');
    $lock = fopen($runtime.'/manager.lock', 'c');
    requireLocal($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Este gestor ya está activo.');
    $record = json_decode(file_get_contents($runtime.'/profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    requireLocal(hash_equals($record['root_sha256'], hash_file('sha256', $rootEnv))
        && hash_equals($record['python_sha256'], hash_file('sha256', $pythonEnv)), 'Los perfiles cambiaron fuera de esta operación.');
    foreach ([18080, 18081] as $port) {
        $socket = @stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
        requireLocal($socket !== false, 'Puerto local ocupado: '.$port);
        fclose($socket);
    }
    $pythonVariables = array_replace($python, cleanLocalEnv(), ['HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1']);
    foreach (array_unique([...array_keys(getenv()), ...array_keys($pythonVariables)]) as $name) {
        if (preg_match('/^(DB_|PG|RESEND_|OPENAI_|HORUS_ADVISOR_)/', $name)) {
            $pythonVariables[$name] = false;
        }
    }
    runLocal([$root.'/chatbot-python/.venv/Scripts/python.exe', '-m', 'app.operacion', 'check'], $root.'/chatbot-python', $pythonVariables);
    $start = function (string $name, array $command, string $cwd, array $env) use (&$children, &$handles, $runtime): void {
        $log = fopen($runtime.'/'.$name.'.log', 'ab');
        $handles[] = $log;
        $process = new Process($command, $cwd, $env);
        $process->setTimeout(null);
        $process->start(static function (string $type, string $output) use ($log): void {
            fwrite($log, $output);
        });
        $children[$name] = $process;
    };
    $start('fastapi', [$root.'/chatbot-python/.venv/Scripts/python.exe', '-m', 'app.operacion', 'run', '--execute'], $root.'/chatbot-python', $pythonVariables);
    $started = time();
    do {
        requireLocal($children['fastapi']->isRunning(), 'FastAPI terminó antes de readiness.');
        $ready = localReady();
        requireLocal(($ready['semantic'] ?? '') !== 'unavailable', 'El modelo declaró un fallo de carga.');
        if (($ready['semantic'] ?? '') === 'loaded' && ($ready['auth'] ?? '') === 'configured') {
            break;
        }
        usleep(250000);
        // Arranque frío medido: 125,48 s de imports + 5,74 s de pesos. No cambia límites HTTP/cola.
    } while (time() - $started < 180);
    requireLocal(($ready['semantic'] ?? '') === 'loaded', 'MiniLM no disponible en el presupuesto existente.');
    $start('worker', [PHP_BINARY, $root.'/artisan', 'horus:advisor-operacion', 'worker', '--execute'], $root, operationalEnv());
    sleep(2);
    requireLocal($children['worker']->isRunning(), 'El worker no pudo iniciarse.');
    runLocal([PHP_BINARY, $root.'/artisan', 'horus:advisor-operacion', 'sync', '--execute'], $root, operationalEnv());
    $started = time();
    do {
        $status = json_decode(runLocal([PHP_BINARY, $root.'/artisan', 'horus:advisor-operacion', 'status', '--probe', '--queue'],
            $root, operationalEnv(), 30, true), true, 512, JSON_THROW_ON_ERROR);
        requireLocal(($status['errors'] ?? ['invalid']) === [], 'Preflight con configuración incompatible.');
        if (($status['ready'] ?? false) === true) {
            break;
        }
        sleep(1);
    } while (time() - $started < 45);
    requireLocal(($status['ready'] ?? false) === true, 'Preflight operativo no aprobado.');
    requireLocal(hash_equals($record['root_sha256'], hash_file('sha256', $rootEnv)), 'El entorno cambió antes de la activación.');
    updateLocalEnv($rootEnv, ['HORUS_ADVISOR_ENABLED' => 'true'], $runtime);
    $record['root_sha256'] = hash_file('sha256', $rootEnv);
    $record['advisor_enabled'] = true;
    file_put_contents($runtime.'/profiles.json', json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX);
    $start('web', [PHP_BINARY, '-S', '127.0.0.1:18080', '-t', $root.'/public',
        $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], $root.'/public', cleanLocalEnv() + ['APP_DEBUG' => 'false']);
    $state = ['manager_pid' => getmypid(), 'web_url' => 'http://127.0.0.1:18080', 'fastapi_url' => 'http://127.0.0.1:18081',
        'started_at' => date(DATE_ATOM), 'ready' => true, 'children' => []];
    foreach ($children as $name => $child) {
        $state['children'][$name] = $child->getPid();
    }
    file_put_contents($runtime.'/status.json', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    if (is_file($runtime.'/failure.json')) {
        $previous = json_decode(file_get_contents($runtime.'/failure.json'), true, 512, JSON_THROW_ON_ERROR);
        $previous['resolved_at'] = date(DATE_ATOM);
        file_put_contents($runtime.'/failure.json', json_encode($previous, JSON_THROW_ON_ERROR), LOCK_EX);
    }
    echo "LOCAL_READY http://127.0.0.1:18080\n";
    $lastRenew = time();
    while (! file_exists($runtime.'/stop.request')) {
        foreach ($children as $name => $child) {
            $child->getIncrementalOutput();
            $child->getIncrementalErrorOutput();
            if (! $child->isRunning()) {
                if ($name === 'worker' && $child->getExitCode() === 0) {
                    $start('worker', [PHP_BINARY, $root.'/artisan', 'horus:advisor-operacion', 'worker', '--execute'], $root, operationalEnv());
                    $state['children']['worker'] = $children['worker']->getPid();
                    file_put_contents($runtime.'/status.json', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
                } else {
                    throw new RuntimeException('Terminó el proceso local: '.$name);
                }
            }
        }
        if (time() - $lastRenew >= 120) {
            LocalCatalogRenewal::attempt(
                static fn () => runLocal([PHP_BINARY, $root.'/artisan', 'horus:advisor-operacion', 'sync', '--execute'], $root, operationalEnv()),
                static function (array $event) use ($runtime): void {
                    file_put_contents($runtime.'/control-events.log', json_encode($event, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
                },
            );
            // No se reintenta una escritura incierta de inmediato. La versión/caducidad real
            // sigue gobernando la contingencia; la siguiente renovación usa su intervalo normal.
            $lastRenew = time();
        }
        sleep(1);
    }
} catch (Throwable $e) {
    $message = get_class($e) === RuntimeException::class ? $e->getMessage() : 'Error técnico local; datos privados omitidos.';
    fwrite(STDERR, $message.PHP_EOL);
    if (is_dir($runtime)) {
        file_put_contents($runtime.'/failure.json', json_encode(['failed' => true, 'message' => $message,
            'exception_type' => $e::class, 'at' => date(DATE_ATOM)]), LOCK_EX);
    }
    $exitCode = 1;
} finally {
    foreach ($children as $child) {
        if ($child->isRunning()) {
            $child->stop(5);
        }
    }
    foreach ($handles as $handle) {
        fclose($handle);
    }
}
exit($exitCode);
