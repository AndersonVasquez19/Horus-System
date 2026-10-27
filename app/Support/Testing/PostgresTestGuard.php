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

    public static function laravelConnection(array $connection): array
    {
        // ConfigurationUrlParser removes url, even when empty; ConnectionFactory adds name.
        unset($connection['url']);

        return $connection + ['name' => 'pgsql'];
    }

    public function assertConfiguration(array $config, array $environment, bool $cached): array
    {
        if ($cached) {
            $this->reject('cached configuration is forbidden.');
        }

        if (($config['app']['env'] ?? null) !== 'testing' || ($environment['APP_ENV'] ?? null) !== 'testing') {
            $this->reject('testing environment required.');
        }

        if (($environment['HORUS_PG_TEST_PROFILE'] ?? null) !== '1'
            || ($environment['HORUS_PG_TEST_CONFIRM'] ?? null) !== self::CONFIRMATION) {
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

        $expected = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
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

        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55432',
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

    /** Identity and authorization must come from the server, not from the supplied environment. */
    public function assertIdentity(array $connection, array $identity): void
    {
        foreach (['database' => $connection['database'], 'username' => $connection['username'],
            'server_address' => '127.0.0.1', 'server_port' => '55432',
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

    /** The callback that can enable writes is never reached on a rejection. */
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
