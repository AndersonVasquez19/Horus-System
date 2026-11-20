<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\PostgresTestPreflight;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Database\Connectors\ConnectorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresSafetyFixtures;

/** All workers, identities and credentials are doubles. No socket or operational consent. */
class PostgresPreflightBoundaryTest extends TestCase
{
    use PostgresSafetyFixtures;

    public static function untrustedStates(): iterable
    {
        yield 'absent' => [[]];
        yield 'environment success' => [['HORUS_PG_PREFLIGHT_VALIDATED' => '1']];
        yield 'forged token' => [['HORUS_PG_PREFLIGHT_TOKEN' => 'fictional-token']];
        yield 'expired token' => [['HORUS_PG_PREFLIGHT_TOKEN' => 'expired', 'HORUS_PG_PREFLIGHT_EXPIRES' => '1']];
        yield 'old validation' => [['HORUS_PG_PREFLIGHT_RESULT' => '{"protocol":"horus-pg-preflight-v1"}']];
        yield 'configuration override' => [['HORUS_PG_PREFLIGHT_CONFIG' => 'alternative.xml']];
    }

    private function refusedPreflight(): PostgresTestPreflight
    {
        $worker = $this->createMock(Process::class);
        $worker->expects($this->once())->method('run')->willReturn(42);
        $worker->method('getOutput')->willReturn('');
        $worker->method('getErrorOutput')->willReturn('fictional refusal');

        return new PostgresTestPreflight(fn () => $worker);
    }

    #[DataProvider('untrustedStates')]
    public function test_direct_profile_installation_needs_fresh_preflight(array $state): void
    {
        $this->withEnvironment($state + $this->safeEnvironment(), function (): void {
            $config = $this->safeConfig();
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);
            try {
                TestDatabaseSafety::install($app, $this->refusedPreflight());
                $this->fail('A failed worker installed the protected profile.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preflight refused', $error->getMessage());
                $this->assertNotInstanceOf(GuardedPostgresConnector::class, $app->make('db.connector.pgsql'));
            }
        });
    }

    #[DataProvider('untrustedStates')]
    public function test_connector_refuses_before_native_connection_without_fresh_preflight(array $state): void
    {
        $this->withEnvironment($state + $this->safeEnvironment(), function (): void {
            $native = $this->createMock(ConnectorInterface::class);
            $native->expects($this->never())->method('connect');
            $connector = new GuardedPostgresConnector(
                fn () => $this->safeConfig()['database']['connections']['pgsql'],
                $native, preflight: $this->refusedPreflight(),
            );
            $this->expectExceptionMessage('preflight refused');
            $connector->connect($this->activeConnection());
        });
    }

    public function test_previous_success_cannot_authorize_a_later_connection(): void
    {
        $worker = $this->createMock(Process::class);
        $worker->expects($this->exactly(2))->method('run')->willReturnOnConsecutiveCalls(0, 42);
        // Fixed fixture identity; no database worker.
        $identity = ['server_version' => '18.6', 'system_identifier' => '7702263743609677164',
            'cluster_name' => 'horus_pgt_55433', 'host' => '127.0.0.1', 'port' => '55433',
            'database' => 'horus_l2_test', 'username' => 'horus_l2_test_runner',
            'transaction_read_only' => 'on', 'default_transaction_read_only' => 'on'];
        $worker->method('getOutput')->willReturn(json_encode(['protocol' => PostgresTestPreflight::PROTOCOL,
            'identity' => $identity], JSON_THROW_ON_ERROR));
        $worker->method('getErrorOutput')->willReturn('');
        $preflight = new PostgresTestPreflight(fn () => $worker);
        $preflight->verify();
        $native = $this->createMock(ConnectorInterface::class);
        $native->expects($this->never())->method('connect');
        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'],
            $native, preflight: $preflight);
        $this->expectExceptionMessage('preflight refused');
        $connector->connect($this->activeConnection());
    }

    public static function alternativeEntryPoints(): iterable
    {
        yield 'alternate bootstrap' => [true];
        yield 'alternate XML' => [false];
    }

    #[DataProvider('alternativeEntryPoints')]
    public function test_cli_substitution_still_hits_application_barrier(bool $override): void
    {
        $root = dirname(__DIR__, 2);
        // This is a local SQLite PHPUnit configuration, never phpunit.pgsql.xml.
        $command = [PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml',
            '--do-not-cache-result'];
        if ($override) {
            $command = [...$command, '--bootstrap', $root.'/vendor/autoload.php'];
        }
        $command[] = $root.'/tests/Fixtures/Preflight/UnauthorizedProfileTest.php';
        $process = new Process($command, $root);
        $this->assertSame(0, $process->run(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('OK (1 test, 2 assertions)', $process->getOutput());
        $this->assertStringNotContainsString('Risky', $process->getOutput());
    }
}
