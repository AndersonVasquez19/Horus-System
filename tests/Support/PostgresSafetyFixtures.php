<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Testing\PostgresTestGuard;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;

trait PostgresSafetyFixtures
{
    private function safeEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing', 'HORUS_PG_TEST_PROFILE' => '1',
            'HORUS_PG_TEST_CONFIRM' => PostgresTestGuard::CONFIRMATION,
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55432',
            'DB_DATABASE' => 'horus_l2_test', 'DB_USERNAME' => 'horus_l2_test_runner',
            'DB_PASSWORD' => 'fictional-test-password', 'DB_URL' => '',
        ];
    }

    private function safeConfig(): array
    {
        return [
            'app' => ['env' => 'testing'],
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => [
                'driver' => 'pgsql', 'url' => '', 'host' => '127.0.0.1', 'port' => '55432',
                'database' => 'horus_l2_test', 'username' => 'horus_l2_test_runner',
                'password' => 'fictional-test-password', 'charset' => 'utf8',
                'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public',
                'sslmode' => 'prefer', 'timezone' => 'UTC', 'application_name' => 'horus_l2_tests',
            ]]],
            'cache' => ['default' => 'array'], 'session' => ['driver' => 'array'],
            'queue' => ['default' => 'sync'], 'mail' => ['default' => 'array'],
            'broadcasting' => ['default' => null],
        ];
    }

    private function safeIdentity(): array
    {
        return [
            'database' => 'horus_l2_test', 'username' => 'horus_l2_test_runner',
            'server_address' => '127.0.0.1', 'server_port' => '55432',
            'search_path' => 'public', 'read_only' => 'on', 'timezone' => 'UTC',
            'isolation' => 'read committed', 'marker' => 'horus-l2-destructive-tests',
            'unsafe_role' => 0, 'role_memberships' => 0, 'other_database_access' => 0,
            'writable_marker' => 0,
        ];
    }

    private function activeConnection(): array
    {
        return PostgresTestGuard::laravelConnection($this->safeConfig()['database']['connections']['pgsql']);
    }

    private function application(array $config): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository($config));
        $app->instance('config_loaded_from_cache', false);
        $app->register(DatabaseServiceProvider::class);

        return $app;
    }

    private function withEnvironment(array $values, Closure $callback): void
    {
        $keys = array_unique([...array_keys($this->safeEnvironment()), ...array_keys($values),
            ...PostgresTestGuard::FORBIDDEN_ENV]);
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            if (array_key_exists($key, $values)) {
                $_ENV[$key] = $_SERVER[$key] = $values[$key];
                putenv($key.'='.$values[$key]);
            } else {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }
        }
        try {
            $callback();
        } finally {
            foreach ($previous as $key => [$env, $server, $process]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
    }
}
