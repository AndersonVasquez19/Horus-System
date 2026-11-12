<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Closure;
use RuntimeException;
use Throwable;

/** Fixed policy: changing a process variable cannot authorize a development database. */
final class PostgresTestGuard
{
    public const TARGETS = [
        'horus_l2_test' => 'horus_l2_test_runner',
        'horus_l2_restore_test' => 'horus_l2_restore_test_runner',
    ];

    public const CONFIRMATION = 'AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS';

    /** Single loopback destination of the independent test cluster (PG-TEST-02a). */
    public const HOST = '127.0.0.1';

    public const PORT = '55433';

    /** Suspended S7 cluster: refused explicitly, never an alternative target. */
    public const RETIRED_PORTS = ['55432'];

    /** Physical identity of the independent cluster created in PG-TEST-02b (read-only probe only). */
    public const SYSTEM_IDENTIFIER = '7702263743609677164';

    public const CLUSTER_NAME = 'horus_pgt_55433';

    public const FORBIDDEN_ENV = [
        'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'DB_QUEUE_CONNECTION',
        'DB_QUEUE_FAILED_CONNECTION', 'SESSION_CONNECTION', 'REDIS_URL',
        'OPENAI_API_KEY', 'RESEND_API_KEY', 'POSTMARK_API_KEY', 'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY', 'SLACK_BOT_USER_OAUTH_TOKEN',
        'PGSERVICE', 'PGSERVICEFILE', 'PGHOST', 'PGHOSTADDR', 'PGPORT', 'PGDATABASE',
        'PGUSER', 'PGPASSWORD', 'PGPASSFILE', 'PGOPTIONS',
    ];

    public function reject(string $reason): never
    {
        // Never include configuration values, passwords or original driver exceptions.
        throw new RuntimeException('Horus PostgreSQL test safety: '.$reason);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public static function laravelConnection(array $connection): array
    {
        // ConfigurationUrlParser removes url, even when empty; ConnectionFactory adds name.
        unset($connection['url']);

        return $connection + ['name' => 'pgsql'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $environment
     * @return array<string, mixed>
     */
    public function assertConfiguration(array $config, array $environment, bool $cached, bool $requireConsent = true): array
    {
        if ($cached) {
            $this->reject('cached configuration is forbidden.');
        }

        if (($config['app']['env'] ?? null) !== 'testing' || ($environment['APP_ENV'] ?? null) !== 'testing') {
            $this->reject('testing environment required.');
        }

        // Only the read-only probe may omit consent; its connector never enables writes.
        if (($environment['HORUS_PG_TEST_PROFILE'] ?? null) !== '1'
            || ($requireConsent && ($environment['HORUS_PG_TEST_CONFIRM'] ?? null) !== self::CONFIRMATION)) {
            $this->reject('explicit isolated profile and destructive-test consent required.');
        }

        $database = $config['database'] ?? [];
        if (($database['default'] ?? null) !== 'pgsql'
            || array_keys($database['connections'] ?? []) !== ['pgsql']) {
            $this->reject('only the pgsql connection may be configured.');
        }

        $connection = $database['connections']['pgsql'];
        $name = $connection['database'] ?? '';
        if (! is_string($name) || ! isset(self::TARGETS[$name])) {
            $this->reject('database is not on the fixed test allowlist.');
        }

        foreach ([$connection['port'] ?? null, $environment['DB_PORT'] ?? null] as $port) {
            if (is_scalar($port) && in_array(trim((string) $port), self::RETIRED_PORTS, true)) {
                $this->reject('retired S7 port is refused.');
            }
        }

        $expected = [
            'driver' => 'pgsql', 'host' => self::HOST, 'port' => self::PORT,
            'database' => $name, 'username' => self::TARGETS[$name],
            'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true,
            'search_path' => 'public', 'sslmode' => 'prefer',
            'timezone' => 'UTC', 'application_name' => 'horus_l2_tests',
        ];

        foreach ($expected as $key => $value) {
            if (($connection[$key] ?? null) !== $value) {
                $this->reject('unexpected connection setting: '.$key.'.');
            }
        }

        if (! is_string($connection['password'] ?? null) || $connection['password'] === '') {
            $this->reject('dedicated password required.');
        }

        if (! empty($connection['url']) || ! empty($environment['DB_URL'])) {
            $this->reject('DB_URL is forbidden.');
        }

        $allowedKeys = [...array_keys($expected), 'password', 'url', 'name'];
        if (array_diff(array_keys($connection), $allowedKeys) !== []
            || (isset($connection['name']) && $connection['name'] !== 'pgsql')) {
            $this->reject('alternate connection options are forbidden.');
        }

        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => self::HOST, 'DB_PORT' => self::PORT,
            'DB_DATABASE' => $name, 'DB_USERNAME' => self::TARGETS[$name], 'DB_PASSWORD' => $connection['password']] as $key => $value) {
            if (($environment[$key] ?? null) !== $value) {
                $this->reject('environment/configuration mismatch: '.$key.'.');
            }
        }

        foreach (['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'mail.default' => 'array', 'broadcasting.default' => null] as $key => $value) {
            [$section, $setting] = explode('.', $key);
            if (($config[$section][$setting] ?? null) !== $value) {
                $this->reject('isolated runtime required: '.$key.'.');
            }
        }

        foreach (self::FORBIDDEN_ENV as $key) {
            if (! empty($environment[$key])) {
                $this->reject('secondary destinations and external credentials are forbidden.');
            }
        }

        return $connection;
    }

    /**
     * Identity and authorization must come from the server, not from the supplied environment.
     *
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $identity
     */
    public function assertIdentity(array $connection, array $identity): void
    {
        if (in_array((string) ($identity['server_port'] ?? ''), self::RETIRED_PORTS, true)) {
            $this->reject('server reports the retired S7 port; no writes authorized.');
        }

        foreach (['database' => $connection['database'], 'username' => $connection['username'],
            'server_address' => self::HOST, 'server_port' => self::PORT,
            'search_path' => 'public', 'read_only' => 'on', 'timezone' => 'UTC',
            'isolation' => 'read committed', 'marker' => 'horus-l2-destructive-tests'] as $key => $expected) {
            if ((string) ($identity[$key] ?? '') !== $expected) {
                $this->reject('server identity or independent authorization mismatch: '.$key.'.');
            }
        }

        foreach (['unsafe_role', 'role_memberships', 'other_database_access', 'writable_marker'] as $key) {
            if (! array_key_exists($key, $identity) || (string) $identity[$key] !== '0') {
                $this->reject('least-privilege or marker ownership check failed.');
            }
        }
    }

    /**
     * Read-only probe: the session must belong to the independent cluster and still be read-only.
     *
     * @param  array<string, mixed>  $cluster
     */
    public function assertClusterIdentity(array $cluster): void
    {
        foreach (['cluster_name' => self::CLUSTER_NAME, 'system_identifier' => self::SYSTEM_IDENTIFIER,
            'transaction_read_only' => 'on', 'default_transaction_read_only' => 'on'] as $key => $expected) {
            if ((string) ($cluster[$key] ?? '') !== $expected) {
                $this->reject('cluster identity or read-only state mismatch: '.$key.'.');
            }
        }
    }

    /**
     * The callback that can enable writes is never reached on a rejection.
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $connection
     * @param  Closure(): array<string, mixed>  $readIdentity
     * @param  Closure(): TResult  $enableWrites
     * @return TResult
     */
    public function authorize(array $connection, Closure $readIdentity, Closure $enableWrites): mixed
    {
        try {
            $identity = $readIdentity();
        } catch (Throwable) {
            $this->reject('read-only identity probe failed; no writes authorized.');
        }

        $this->assertIdentity($connection, $identity);

        return $enableWrites();
    }
}
