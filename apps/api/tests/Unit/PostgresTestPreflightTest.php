<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\PostgresTestPreflight;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresSafetyFixtures;

class PostgresTestPreflightTest extends TestCase
{
    use PostgresSafetyFixtures;

    private function identity(): array
    {
        return ['server_version' => '18.6', 'system_identifier' => '7702263743609677164',
            'cluster_name' => 'horus_pgt_55433', 'host' => '127.0.0.1', 'port' => '55433',
            'database' => 'horus_l2_test', 'username' => 'horus_l2_test_runner',
            'transaction_read_only' => 'on', 'default_transaction_read_only' => 'on'];
    }

    private function protocolJson(array $identity): string
    {
        return json_encode(['protocol' => 'horus-pg-preflight-v1', 'identity' => $identity], JSON_THROW_ON_ERROR);
    }

    public function test_read_only_worker_must_finish_before_phpunit_is_created(): void
    {
        $worker = $this->createMock(Process::class);
        $finished = false;
        $worker->expects($this->once())->method('run')->willReturnCallback(function () use (&$finished): int {
            $finished = true;

            return 0;
        });
        $worker->method('getOutput')->willReturn($this->protocolJson($this->identity()));
        $worker->method('getErrorOutput')->willReturn('');
        $phpunit = $this->createMock(Process::class);
        $phpunit->expects($this->once())->method('run')->willReturn(0);
        $commands = [];
        $factory = function (array $command) use (&$commands, &$finished, $worker, $phpunit): Process {
            $commands[] = $command;
            if (count($commands) === 2) {
                $this->assertTrue($finished, 'Preflight must finish before creating PHPUnit.');
            }

            return count($commands) === 1 ? $worker : $phpunit;
        };
        $this->assertSame(0, (new PostgresTestPreflight($factory))->runTests(['--filter=Example', '--stop-on-error']));
        $this->assertSame(PHP_BINARY, $commands[0][0]);
        $this->assertStringEndsWith('/scripts/pgsql-test-preflight.php', $commands[0][1]);
        $this->assertStringEndsWith('/vendor/bin/phpunit', $commands[1][1]);
        $this->assertContains('--stop-on-error', $commands[1]);
        $this->assertContains('--stop-on-failure', $commands[1]);
    }

    public static function invalidResults(): iterable
    {
        yield 'native failure' => [7, '', 'refused'];
        yield 'stderr with success' => [0, '{}', 'warning'];
        yield 'empty output' => [0, '', ''];
        yield 'invalid json' => [0, 'not-json', ''];
        yield 'missing protocol' => [0, '{"identity":{}}', ''];
        yield 'missing identity' => [0, '{"protocol":"horus-pg-preflight-v1"}', ''];
    }

    #[DataProvider('invalidResults')]
    public function test_any_preflight_failure_prevents_phpunit_creation(int $exit, string $stdout, string $stderr): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willReturn($exit);
        $process->expects($this->once())->method('getOutput')->willReturn($stdout);
        $process->expects($this->once())->method('getErrorOutput')->willReturn($stderr);
        $calls = 0;
        $preflight = new PostgresTestPreflight(function () use ($process, &$calls): Process {
            $this->assertSame(1, ++$calls, 'PHPUnit must not be created after rejection.');

            return $process;
        });
        $this->expectException(RuntimeException::class);
        $preflight->runTests([]);
    }

    public static function mismatches(): iterable
    {
        foreach (['server_version', 'system_identifier', 'cluster_name', 'host', 'port', 'database',
            'username', 'transaction_read_only', 'default_transaction_read_only'] as $key) {
            foreach (['different', 'missing', 'unreadable'] as $mode) {
                yield $key.' '.$mode => [$key, $mode];
            }
        }
    }

    #[DataProvider('mismatches')]
    public function test_each_identity_field_is_mandatory_and_exact(string $key, string $mode): void
    {
        $identity = $this->identity();
        if ($mode === 'missing') {
            unset($identity[$key]);
        } else {
            $identity[$key] = $mode === 'unreadable' ? [] : 'unexpected';
        }
        $process = $this->createMock(Process::class);
        $process->method('run')->willReturn(0);
        $process->method('getOutput')->willReturn($this->protocolJson($identity));
        $process->method('getErrorOutput')->willReturn('');
        $this->expectException(RuntimeException::class);
        (new PostgresTestPreflight(fn () => $process))->verify();
    }

    public function test_launch_exception_is_sanitized_and_blocks_dispatch(): void
    {
        $preflight = new PostgresTestPreflight(static function (): never {
            throw new RuntimeException('fictional-sensitive-launch-detail');
        });
        try {
            $preflight->runTests([]);
            $this->fail('Expected a launch rejection.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('fictional-sensitive-launch-detail', $error->getMessage());
            $this->assertStringContainsString('preflight', $error->getMessage());
        }
    }

    public function test_bootstrap_contains_no_laravel_initialization_and_always_calls_preflight(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__).'/bootstrap-pgsql.php');
        $this->assertStringNotContainsString("'/bootstrap/app.php'", $bootstrap);
        $this->assertStringNotContainsString('Kernel::class', $bootstrap);
        $this->assertStringNotContainsString('getPdo', $bootstrap);
        $this->assertStringContainsString('(new PostgresTestPreflight)->verify();', $bootstrap);
        $this->assertSame('tests/bootstrap-pgsql.php', (string) simplexml_load_file(dirname(__DIR__, 2).'/phpunit.pgsql.xml')['bootstrap']);
    }

    public static function disallowedWorkerConfigurations(): iterable
    {
        yield 'missing consent' => [false, false];
        yield 'restore database even with simulated consent' => [true, true];
    }

    #[DataProvider('disallowedWorkerConfigurations')]
    public function test_read_only_worker_refuses_missing_consent_and_restore_before_resolving_db(bool $consent, bool $restore): void
    {
        $environment = $this->safeEnvironment();
        if (! $consent) {
            $environment['HORUS_PG_TEST_CONFIRM'] = '';
        }
        $config = $this->safeConfig();
        if ($restore) {
            $config['database']['connections']['pgsql']['database'] = $environment['DB_DATABASE'] = 'horus_l2_restore_test';
            $config['database']['connections']['pgsql']['username'] = $environment['DB_USERNAME'] = 'horus_l2_restore_test_runner';
        }
        $this->withEnvironment($environment, function () use ($config): void {
            $app = $this->application($config);
            $app->bind('db', function (): never {
                $this->fail('A rejected worker must never resolve the database manager.');
            });
            PostgresTestPreflight::prepare($app);
            $this->assertTrue(TestDatabaseSafety::readOnlyProbe($app));
            $this->expectExceptionMessage('primary test database and destructive-test consent');
            $app['events']->dispatch('bootstrapped: '.LoadConfiguration::class, [$app]);
        });
    }

    public function test_worker_sets_read_only_mode_before_configuration_validation(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $app = $this->application($this->safeConfig());
            PostgresTestPreflight::prepare($app);
            $app['events']->dispatch('bootstrapped: '.LoadConfiguration::class, [$app]);
            $this->assertTrue(TestDatabaseSafety::readOnlyProbe($app));
        });
    }

    public function test_unapproved_runner_options_never_launch_any_process(): void
    {
        $preflight = new PostgresTestPreflight(function (): never {
            $this->fail('Invalid options must fail before launching even a preflight.');
        });
        $this->expectExceptionMessage('Only --filter=');
        $preflight->runTests(['--bootstrap=vendor/autoload.php']);
    }

    public function test_worker_uses_only_read_only_connector_and_identity_select_after_guarded_pdo(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $app = $this->application($this->safeConfig());
            $kernel = $this->createMock(Kernel::class);
            $kernel->expects($this->once())->method('bootstrap')->willReturnCallback(function () use ($app): void {
                $app['events']->dispatch('bootstrapped: '.LoadConfiguration::class, [$app]);
            });
            $app->instance(Kernel::class, $kernel);
            $app->instance('db.connector.pgsql', new GuardedPostgresConnector(
                fn () => $this->safeConfig()['database']['connections']['pgsql'], readOnlyProbe: true,
            ));
            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->never())->method('exec');
            $connection = $this->createMock(Connection::class);
            $connection->expects($this->once())->method('getPdo')->willReturn($pdo);
            $connection->expects($this->never())->method('statement');
            $connection->expects($this->once())->method('selectOne')->with($this->callback(function (string $sql): bool {
                return str_starts_with($sql, 'SELECT ')
                    && str_contains($sql, 'host(inet_server_addr())')
                    && str_contains($sql, 'default_transaction_read_only');
            }))->willReturn((object) $this->identity());
            $db = $this->createMock(DatabaseManager::class);
            $db->expects($this->once())->method('connection')->with('pgsql')->willReturn($connection);
            $app->instance('db', $db);
            $this->assertSame($this->identity(), PostgresTestPreflight::inspect($app));
            $this->assertTrue(TestDatabaseSafety::readOnlyProbe($app));
        });
    }
}
