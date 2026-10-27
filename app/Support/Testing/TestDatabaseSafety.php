<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Env;

final class TestDatabaseSafety
{
    public static function install(Application $app): void
    {
        $config = $app->make('config');
        $profile = (string) Env::get('HORUS_PG_TEST_PROFILE', '');
        $testing = $config->get('app.env') === 'testing' || Env::get('APP_ENV') === 'testing'
            || defined('PHPUNIT_COMPOSER_INSTALL');
        $testTarget = isset(PostgresTestGuard::TARGETS[$config->get('database.connections.pgsql.database', '')]);

        if (! $testing && $profile === '' && ! $testTarget) {
            return;
        }

        $guard = new PostgresTestGuard;
        foreach (['sqlite', 'mysql', 'mariadb', 'sqlsrv', 'pgsql'] as $driver) {
            $app->bind('db.connector.'.$driver, fn () => new MemoryOnlyConnector($profile === ''));
        }
        if ($profile !== '') {
            if ($app->configurationIsCached() || $app->make('config_loaded_from_cache')) {
                $guard->reject('cached configuration is forbidden.');
            }
            // Laravel merges default driver definitions while loading config. Replace the whole
            // database namespace with this explicit profile before any provider can resolve DB.
            $config->set('database', $config->get('pgsql-testing-database'));
            self::postgresConfiguration($app);
            // Extenders survive the later DatabaseServiceProvider binding. Keep this
            // application-scoped: a static Connection resolver would leak into ordinary apps.
            $app->extend('db.factory', static fn () => new GuardedConnectionFactory($app));
            $app->bind('db.connector.pgsql', fn () => new GuardedPostgresConnector(
                fn (): array => self::postgresConfiguration($app),
            ));
            // Install on resolution, including HTTP clients requested while providers boot.
            $app->afterResolving(Factory::class, static fn (Factory $http) => $http->preventStrayRequests());
        } elseif ($app->configurationIsCached() || $config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:'
            || ! empty($config->get('database.connections.sqlite.url'))) {
            $guard->reject('ordinary tests require uncached SQLite :memory:; PostgreSQL needs its explicit profile.');
        }

        // Applies to Artisan and tests outside Tests\TestCase, including DB::build().
        $app->make('events')->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($app, $profile, $guard): void {
            $connection = $event->connection;
            if ($profile !== '') {
                $expected = self::postgresConfiguration($app);
                if ($connection->getName() !== 'pgsql' || $connection->getConfig() !== PostgresTestGuard::laravelConnection($expected)) {
                    $guard->reject('secondary or modified connection refused.');
                }
            } elseif ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
                $guard->reject('ordinary tests cannot open persistent or secondary databases.');
            }
        });
    }

    public static function postgresConfiguration(Application $app): array
    {
        $environment = [];
        foreach (['APP_ENV', 'HORUS_PG_TEST_PROFILE', 'HORUS_PG_TEST_CONFIRM', 'DB_CONNECTION',
            'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
            ...PostgresTestGuard::FORBIDDEN_ENV] as $key) {
            $value = Env::get($key);
            $environment[$key] = is_scalar($value) ? (string) $value : $value;
            // Reject conflicting PHP/environment sources instead of relying on their precedence.
            foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key) === false ? null : getenv($key)] as $source) {
                if ($source !== null && (string) $source !== (string) $environment[$key]) {
                    (new PostgresTestGuard)->reject('conflicting environment sources.');
                }
            }
        }

        return (new PostgresTestGuard)->assertConfiguration(
            $app->make('config')->all(), $environment,
            $app->configurationIsCached() || $app->make('config_loaded_from_cache'),
        );
    }
}
