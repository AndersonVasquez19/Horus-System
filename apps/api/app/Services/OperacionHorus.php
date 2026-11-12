<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Throwable;

/** Diagnóstico CLI opt-in: nunca devuelve mensajes, secretos, payloads ni excepciones. */
class OperacionHorus
{
    public function programar(): void
    {
        if (! config('horus-ops.enabled') || ! config('horus-ops.renew_catalog')) {
            return;
        }
        Schedule::command('horus:advisor-operacion sync --execute')->everyTwoMinutes()
            ->when(function (): bool {
                $beat = Cache::get('horus.ops.worker');

                return is_array($beat) && is_int($beat['at'] ?? null) && $beat['at'] <= time() && $beat['at'] >= time() - 90;
            })->withoutOverlapping(2);
    }

    /** @return list<string> */
    public function errores(): array
    {
        if (! config('horus-ops.enabled')) {
            return [];
        }
        $errores = [];
        if (! config('horus-advisor.enabled') || ! config('horus-catalog.sync_enabled')) {
            $errores[] = 'advisor_sync_disabled';
        }
        $url = parse_url((string) config('horus-advisor.url'));
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
            || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true) || isset($url['user']) || isset($url['pass'])) {
            $errores[] = 'advisor_url';
        }
        foreach (['token' => 'service_token', 'signing_key' => 'catalog_key'] as $key => $code) {
            if (strlen((string) config('horus-advisor.'.$key)) < 32) {
                $errores[] = $code;
            }
        }
        $connection = (string) config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') !== 'database') {
            $errores[] = 'async_queue';
        } elseif ((int) config('queue.connections.'.$connection.'.retry_after') <= 45) {
            $errores[] = 'retry_after';
        }
        $cache = (string) config('cache.default');
        $driver = config('cache.stores.'.$cache.'.driver');
        if (! in_array($driver, ['file', 'database', 'redis'], true)
            && ! ($driver === 'array' && app()->runningUnitTests())) {
            $errores[] = 'shared_cache';
        }

        return $errores;
    }

    /** @return array<string, mixed> */
    public function estado(bool $probe = false, bool $queue = false): array
    {
        $errors = $this->errores();
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        $status = ['enabled' => (bool) config('horus-ops.enabled'), 'errors' => $errors,
            'ready' => false, 'probe' => 'not_requested', 'worker' => 'not_checked',
            'queue_driver' => in_array($driver, ['database', 'sync', 'redis', 'null'], true) ? $driver : 'unsupported'];
        if (! $probe || ! $status['enabled'] || $errors !== []) {
            return $status;
        }
        try {
            $beat = Cache::get('horus.ops.worker');
            $status['worker'] = is_array($beat) && is_int($beat['at'] ?? null)
                && $beat['at'] <= time() && $beat['at'] >= time() - 90 ? 'alive' : 'stale';
            $expected = Cache::get(config('horus-catalog.expected_version_cache_key'));
            $health = $this->consultar('/health');
            $ready = $this->consultar('/ready');
            $info = $this->consultar('/api/v1/info');
            $status['probe'] = 'completed';
            $status['health'] = ($health['status'] ?? '') === 'ok';
            foreach (['knowledge', 'auth', 'semantic', 'catalog'] as $key) {
                // Nunca reenviar valores libres del servidor remoto a consola/logs.
                $status[$key] = in_array($ready[$key] ?? null,
                    ['loaded', 'none', 'stale', 'error', 'disabled', 'loading', 'unavailable', 'configured', 'missing'], true)
                    ? $ready[$key] : 'invalid';
            }
            $version = $ready['catalog_version'] ?? null;
            $status['catalog_version'] = is_string($version) && preg_match('/\A[a-f0-9]{40}\z/D', $version) ? $version : null;
            $status['version_matches'] = is_string($expected) && $status['catalog_version'] !== null && hash_equals($expected, $status['catalog_version']);
            $status['authenticated'] = ($info['search_mode'] ?? null) === 'hybrid'
                && ($info['catalog_version'] ?? null) === $status['catalog_version'];
            $status['ready'] = $status['health'] && ($ready['status'] ?? '') === 'ready'
                && $status['knowledge'] === 'loaded' && $status['auth'] === 'configured'
                && $status['semantic'] === 'loaded' && $status['catalog'] === 'loaded'
                && $status['version_matches'] && $status['authenticated'] && $status['worker'] === 'alive';
            if ($queue) {
                $this->contarCola($status);
            }
        } catch (Throwable) {
            $status['probe'] = 'unavailable';
            $status['ready'] = false;
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function consultar(string $path): array
    {
        $response = Http::connectTimeout(0.4)->timeout(1)->withoutRedirecting()->withOptions(['stream' => true])
            ->withHeaders($path === '/api/v1/info' ? ['X-Horus-Service-Token' => (string) config('horus-advisor.token')] : [])
            ->get(rtrim((string) config('horus-advisor.url'), '/').$path);
        $body = $response->toPsrResponse()->getBody();
        try {
            $raw = $body->read(4097);
            if (! $response->successful() || strlen($raw) > 4096) {
                return [];
            }
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        } finally {
            $body->close();
        }
    }

    /** @param array<string, mixed> $status */
    private function contarCola(array &$status): void
    {
        $config = config('queue.connections.'.config('queue.default'));
        $db = DB::connection($config['connection'] ?? null);
        $status['pending_queue_total'] = $db->table($config['table'])->where('queue', $config['queue'])->count();
        $status['failed_catalog_jobs'] = DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))->where('payload', 'like', '%SincronizarCatalogoAdvisor%')->count();
    }
}
