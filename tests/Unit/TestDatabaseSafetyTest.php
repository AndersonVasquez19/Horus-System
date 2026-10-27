<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\MemoryOnlyConnector;
use App\Support\Testing\TestDatabaseSafety;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\PostgresSchemaState;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresSafetyFixtures;

class TestDatabaseSafetyTest extends TestCase
{
    use PostgresSafetyFixtures;

    public function test_framework_bootstrap_rejects_pgsql_before_providers_and_refresh_database(): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'horus_group', 'HORUS_PG_TEST_PROFILE' => ''], function (): void {
                $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
                $this->isolateEnvironmentFile($app);
                $migrationKernel = $this->createMock(Kernel::class);
                $migrationKernel->expects($this->never())->method('call');
                $app->afterBootstrapping(BootProviders::class, fn () => $migrationKernel->call('migrate:fresh'));
                try {
                    $app->make(Kernel::class)->bootstrap();
                    $this->fail('Expected rejection before providers.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('ordinary tests require', $error->getMessage());
                    $this->assertArrayNotHasKey(DatabaseServiceProvider::class, $app->getLoadedProviders());
                }
            });
    }

    public function test_profile_checks_artisan_bootstrap_before_any_migration_dispatch(): void
    {
        $environment = $this->safeEnvironment();
        $environment['DB_DATABASE'] = 'horus_group';
        $environment += ['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null'];
        $this->withEnvironment($environment, function (): void {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->isolateEnvironmentFile($app);
            $kernel = $this->createMock(Kernel::class);
            // All planned destructive dispatches stay behind the bootstrap barrier.
            $kernel->expects($this->never())->method('call');
            try {
                $app->make(Kernel::class)->bootstrap();
                foreach (['migrate:fresh', 'db:wipe', 'db:seed', 'migrate:rollback'] as $command) {
                    $kernel->call($command);
                }
                $this->fail('Expected rejection.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('fixed test allowlist', $error->getMessage());
            }
        });
    }

    public function test_plain_phpunit_or_artisan_connections_cannot_use_a_secondary_driver(): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'HORUS_PG_TEST_PROFILE' => ''], function (): void {
            $config = $this->safeConfig();
            $config['database']['default'] = 'sqlite';
            $config['database']['connections']['sqlite'] = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $driver = $this->createMock(ConnectorInterface::class);
            $driver->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', $driver);
            $this->expectExceptionMessage('ordinary tests cannot open persistent or secondary databases');
            $app->make('db')->connection('pgsql')->getPdo();
        });
    }

    public function test_profile_installs_only_its_database_and_rejects_dynamic_build_without_connecting(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            // These are framework-merged defaults, discarded by the explicit profile.
            $config['database']['connections']['mysql'] = ['driver' => 'mysql', 'database' => 'daily'];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $this->assertSame(['pgsql'], array_keys($app['config']['database.connections']));
            $driver = $this->createMock(ConnectorInterface::class);
            $driver->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', $driver);
            $this->expectExceptionMessage('secondary or modified connection refused');
            $app->make('db')->build($this->safeConfig()['database']['connections']['pgsql'])->getPdo();
        });
    }

    public function test_profile_can_resolve_the_expected_connection_lazily_without_opening_it(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $connection = $app->make('db')->connection('pgsql');
            $this->assertSame('horus_l2_test', $connection->getDatabaseName());
            $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        });
    }

    public function test_conflicting_process_and_php_environment_sources_are_rejected(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $_SERVER['DB_DATABASE'] = 'horus_group';
            $app = $this->application($this->safeConfig());
            $this->expectExceptionMessage('conflicting environment sources');
            TestDatabaseSafety::postgresConfiguration($app);
        });
    }

    public function test_http_is_blocked_on_resolution_before_providers_boot(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $http = $this->createMock(Factory::class);
            $http->expects($this->once())->method('preventStrayRequests')->willReturnSelf();
            $app->bind(Factory::class, fn () => $http);
            $this->assertSame($http, $app->make(Factory::class));
        });
    }

    public function test_cached_configuration_is_rejected_before_profile_override(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $app = $this->application($this->safeConfig());
            $app->instance('config_loaded_from_cache', true);
            $this->expectExceptionMessage('cached configuration is forbidden');
            TestDatabaseSafety::install($app);
        });
    }

    public function test_forced_pdo_resolution_and_retry_after_rejection_cannot_bypass_safety(): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'HORUS_PG_TEST_PROFILE' => ''], function (): void {
            $config = $this->safeConfig();
            $config['database']['default'] = 'sqlite';
            $config['database']['connections']['sqlite'] = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $driver = $this->createMock(ConnectorInterface::class);
            $driver->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', new MemoryOnlyConnector(true, $driver));

            foreach (['pgsql', 'pgsql', 'pgsql::read', 'pgsql::write'] as $name) {
                try {
                    $app->make('db')->connection($name)->getPdo();
                    $this->fail('A rejected or forced connection must remain blocked.');
                } catch (RuntimeException $error) {
                    $this->assertStringStartsWith('Horus PostgreSQL test safety:', $error->getMessage());
                }
            }
        });
    }

    public static function schemaDumpPaths(): iterable
    {
        yield 'SQL' => ['database/schema/pgsql-schema.sql'];
        yield 'dump' => ['database/schema/pgsql-schema.dump'];
        yield 'alternate connection name' => ['database/schema/renamed-schema.sql'];
        yield 'alternate absolute path' => ['C:/untrusted/restore.sql'];
        yield 'alternate extension' => ['arbitrary.backup'];
    }

    #[DataProvider('schemaDumpPaths')]
    public function test_profile_schema_load_rejects_any_path_before_creating_a_native_process(string $path): void
    {
        $this->withEnvironment($this->safeEnvironment(), function () use ($path): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);
            $driver = $this->createMock(ConnectorInterface::class);
            $driver->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', $driver);
            $process = $this->createMock(Process::class);
            $process->expects($this->never())->method('mustRun');
            // The factory itself must not be reached, not just Process::mustRun().
            $processFactory = function (...$arguments) use ($process) {
                $this->fail('Native process factory reached.');

                return $process;
            };
            $state = $app['db']->connection('pgsql')->getSchemaState(null, $processFactory);
            $this->assertInstanceOf(PostgresSchemaState::class, $state);
            $this->expectExceptionMessage('schema dump loading is forbidden');
            $state->load($path);
        });
    }

    public function test_schema_guard_survives_provider_registration_and_is_scoped_to_its_application(): void
    {
        $environment = $this->safeEnvironment() + ['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null'];
        $this->withEnvironment($environment, function (): void {
            // Both entry points use this bootstrap before dispatch or test execution.
            foreach (['scripts/pgsql-test.php', 'tests/bootstrap-pgsql.php'] as $entry) {
                $this->assertStringContainsString(".'/bootstrap/app.php'", file_get_contents(dirname(__DIR__, 2).'/'.$entry));
                $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
                $this->isolateEnvironmentFile($app);
                $driver = $this->createMock(ConnectorInterface::class);
                $driver->expects($this->never())->method('connect');
                $app->afterBootstrapping(LoadConfiguration::class, fn () => $app->instance('db.connector.pgsql', $driver));
                $app->make(Kernel::class)->bootstrap();
                // These full bootstraps install handlers; retain PHPUnit's handlers.
                restore_error_handler();
                restore_exception_handler();
                try {
                    $app['db']->connection('pgsql')->getSchemaState(null, function () {
                        $this->fail('Native process factory reached.');
                    })->load('alternate.sql');
                    $this->fail('Expected schema load rejection.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('schema dump loading is forbidden', $error->getMessage());
                }
            }
            $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.pgsql.xml');
            $this->assertSame('tests/bootstrap-pgsql.php', (string) $xml['bootstrap']);
            // No static Connection::resolverFor override may leak into another application.
            $normal = $this->application($this->safeConfig());
            $connection = $normal['db']->connection('pgsql');
            $this->assertSame(PostgresConnection::class, $connection::class);
            $this->assertSame(PostgresSchemaState::class, $connection->getSchemaState()::class);
        });
    }

    public function test_refresh_database_fresh_and_migrate_reject_schema_loading_without_native_processes(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);
            $app->instance('env', 'testing');
            TestDatabaseSafety::install($app);
            $driver = $this->createMock(ConnectorInterface::class);
            $driver->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', $driver);
            $state = $app['db']->connection('pgsql')->getSchemaState(null, function () {
                $this->fail('Native restore process factory reached.');
            });
            $connection = $this->createMock(PostgresConnection::class);
            $connection->method('getSchemaState')->willReturn($state);
            $connection->method('getName')->willReturn('pgsql');
            $migrator = $this->createMock(Migrator::class);
            $migrator->method('usingConnection')->willReturnCallback(fn ($name, $callback) => $callback());
            // Fresh skips wiping; migrate sees an existing (simulated) repository.
            $migrator->method('repositoryExists')->willReturnOnConsecutiveCalls(false, true);
            $migrator->method('hasRunAnyMigrations')->willReturn(false);
            $migrator->method('resolveConnection')->willReturn($connection);
            $migrator->expects($this->once())->method('deleteRepository'); // Double: no SQL.
            $migrator->expects($this->never())->method('run');
            $console = new class($app, $app['events'], 'audit') extends \Illuminate\Console\Application
            {
                protected function bootstrap()
                {
                    // Only the two commands below belong to this simulated console.
                }
            };
            $console->add(new FreshCommand($migrator));
            $console->add(new MigrateCommand($migrator, $app['events']));
            $path = tempnam(sys_get_temp_dir(), 'horus-schema-');
            $this->assertNotFalse($path);
            try {
                $refresh = new class($console, $path)
                {
                    use RefreshDatabase;

                    public function __construct(private $console, private string $path) {}

                    public function invokeMigrateDatabases(): void
                    {
                        $this->migrateDatabases();
                    }

                    public function artisan($command, $parameters = [])
                    {
                        return $this->console->call($command, $parameters + ['--schema-path' => $this->path]);
                    }
                };
                $this->expectExceptionMessage('schema dump loading is forbidden');
                $refresh->invokeMigrateDatabases();
            } finally {
                unlink($path);
            }
        });
    }

    public function test_isolated_environment_fixture_has_no_variables_and_replaces_the_project_env(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/env/.env.safety-fixture';
        $lines = file($fixture, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);
        foreach ($lines as $line) {
            $line = trim($line);
            // Solo comentarios: una asignación podría alterar el perfil o filtrar un valor.
            $this->assertTrue($line === '' || str_starts_with($line, '#'), 'Fixture line is not a comment: '.$line);
        }

        $app = new Application(dirname(__DIR__, 2));
        $this->isolateEnvironmentFile($app);
        $this->assertSame(realpath($fixture), realpath($app->environmentFilePath()));
        $this->assertNotSame(dirname(__DIR__, 2), realpath($app->environmentPath()));
    }

    /**
     * Apunta el arranque a un .env vacío versionado en lugar del .env real del proyecto.
     * Un archivo inexistente también evitaría el .env real, pero phpdotenv avisa al leerlo
     * y el runner de Artisan lo reporta como warning.
     */
    private function isolateEnvironmentFile(Application $app): void
    {
        $app->useEnvironmentPath(dirname(__DIR__).'/Fixtures/env');
        $app->loadEnvironmentFrom('.env.safety-fixture');
    }
}
