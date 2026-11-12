<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\PostgresTestGuard;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Foundation\Application;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgresSafetyFixtures;

/**
 * PG-TEST-03: modo `probe` de solo lectura. Solo dobles; nunca se abre un socket.
 */
class ReadOnlyProbeTest extends TestCase
{
    use PostgresSafetyFixtures;

    public function test_cluster_constants_are_the_independent_cluster(): void
    {
        $this->assertSame('7702263743609677164', PostgresTestGuard::SYSTEM_IDENTIFIER);
        $this->assertSame('horus_pgt_55433', PostgresTestGuard::CLUSTER_NAME);
        $this->assertSame('horus.pgsql.read_only_probe', TestDatabaseSafety::READ_ONLY_PROBE);
    }

    public function test_probe_configuration_does_not_need_consent_but_default_still_does(): void
    {
        $environment = ['HORUS_PG_TEST_CONFIRM' => ''] + $this->safeEnvironment();
        $connection = (new PostgresTestGuard)->assertConfiguration($this->safeConfig(), $environment, false, requireConsent: false);
        $this->assertSame('55433', $connection['port']);

        $this->expectExceptionMessage('explicit isolated profile and destructive-test consent required');
        (new PostgresTestGuard)->assertConfiguration($this->safeConfig(), $environment, false);
    }

    public static function unsafeProbeTargets(): iterable
    {
        yield 'S7 port' => [['port' => '55432'], ['DB_PORT' => '55432'], 'retired S7 port is refused'];
        yield 'daily port' => [['port' => '5432'], ['DB_PORT' => '5432'], 'unexpected connection setting: port'];
        yield 'localhost' => [['host' => 'localhost'], ['DB_HOST' => 'localhost'], 'unexpected connection setting: host'];
        yield 'daily database' => [['database' => 'horus_group'], ['DB_DATABASE' => 'horus_group'], 'database is not on the fixed test allowlist'];
        yield 'rehearsal database' => [['database' => 'horus_group_ensayo'], ['DB_DATABASE' => 'horus_group_ensayo'], 'database is not on the fixed test allowlist'];
        yield 'missing profile' => [[], ['HORUS_PG_TEST_PROFILE' => ''], 'explicit isolated profile and destructive-test consent required'];
        yield 'libpq override' => [[], ['PGPORT' => '55432'], 'secondary destinations and external credentials are forbidden'];
        yield 'URL' => [[], ['DB_URL' => 'pgsql://127.0.0.1:55432/horus_l2_test'], 'DB_URL is forbidden'];
    }

    #[DataProvider('unsafeProbeTargets')]
    public function test_probe_keeps_every_other_configuration_guard(array $connection, array $environment, string $reason): void
    {
        $config = $this->safeConfig();
        $config['database']['connections']['pgsql'] = array_replace($config['database']['connections']['pgsql'], $connection);
        $this->expectExceptionMessage($reason);
        (new PostgresTestGuard)->assertConfiguration($config,
            array_replace(['HORUS_PG_TEST_CONFIRM' => ''] + $this->safeEnvironment(), $environment), false, requireConsent: false);
    }

    public function test_probe_returns_a_read_only_session_and_never_enables_writes(): void
    {
        $pdo = $this->pdo([$this->safeIdentity(), $this->clusterIdentity()]);
        $pdo->expects($this->never())->method('exec');

        $this->assertSame($pdo, $this->probeConnector($pdo)->connect($this->activeConnection()));
    }

    public static function unsafeClusters(): iterable
    {
        yield 'other cluster (S7)' => ['system_identifier', '7701505916098505688'];
        yield 'discarded cluster' => ['system_identifier', '7702261264381348568'];
        yield 'missing identifier' => ['system_identifier', null];
        yield 'other cluster name' => ['cluster_name', 'horus_l2_test'];
        yield 'writable transaction' => ['transaction_read_only', 'off'];
        yield 'writable default' => ['default_transaction_read_only', 'off'];
    }

    #[DataProvider('unsafeClusters')]
    public function test_probe_rejects_a_different_or_writable_cluster(string $key, ?string $value): void
    {
        $pdo = $this->pdo([$this->safeIdentity(), array_replace($this->clusterIdentity(), [$key => $value])]);
        $pdo->expects($this->never())->method('exec');

        $this->expectExceptionMessage('cluster identity or read-only state mismatch: '.$key);
        $this->probeConnector($pdo)->connect($this->activeConnection());
    }

    public function test_unreadable_system_identifier_is_rejected_without_escalation(): void
    {
        $identity = $this->createMock(PDOStatement::class);
        $identity->method('fetch')->willReturn($this->safeIdentity());
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($identity): PDOStatement {
            if (str_contains($sql, 'pg_control_system')) {
                throw new PDOException('permission denied for function pg_control_system; password=DO_NOT_EXPOSE');
            }

            return $identity;
        });
        $pdo->expects($this->never())->method('exec');

        try {
            $this->probeConnector($pdo)->connect($this->activeConnection());
            $this->fail('Unreadable cluster identity accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Horus PostgreSQL test safety: cluster identity probe failed; no writes authorized.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_marker_or_privilege_failure_stops_the_probe_before_cluster_query(): void
    {
        foreach ([['marker' => 'development'], ['unsafe_role' => 1], ['other_database_access' => 1],
            ['writable_marker' => 1], ['server_port' => '55432']] as $override) {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetch')->willReturn(array_replace($this->safeIdentity(), $override));
            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())->method('query')->willReturn($statement);
            $pdo->expects($this->never())->method('exec');
            try {
                $this->probeConnector($pdo)->connect($this->activeConnection());
                $this->fail('Unsafe identity accepted: '.array_key_first($override));
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('Horus PostgreSQL test safety:', $error->getMessage());
            }
        }
    }

    public function test_default_connector_still_enables_writes_only_after_the_probe(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($this->safeIdentity());
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))->method('query')->willReturn($statement);
        $pdo->expects($this->once())->method('exec')->with('SET default_transaction_read_only = off');
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->method('connect')->willReturn($pdo);

        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver, preflight: $this->simulatedPreflight());
        $this->assertFalse($connector->isReadOnlyProbe());
        $connector->connect($this->activeConnection());
    }

    public function test_profile_installs_the_read_only_connector_only_with_the_container_flag(): void
    {
        $environment = ['HORUS_PG_TEST_CONFIRM' => ''] + $this->safeEnvironment();
        $this->withEnvironment($environment, function (): void {
            $app = $this->profileApplication(true);
            $connector = $app->make('db.connector.pgsql');
            $this->assertInstanceOf(GuardedPostgresConnector::class, $connector);
            $this->assertTrue($connector->isReadOnlyProbe());
            $this->assertSame('55433', TestDatabaseSafety::postgresConfiguration($app)['port']);
        });
    }

    public static function notTheProbeFlag(): iterable
    {
        yield 'no binding' => [null];
        yield 'string true' => ['true'];
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
    }

    #[DataProvider('notTheProbeFlag')]
    public function test_without_the_exact_flag_consent_is_still_required(mixed $flag): void
    {
        $environment = ['HORUS_PG_TEST_CONFIRM' => ''] + $this->safeEnvironment();
        $this->withEnvironment($environment, function () use ($flag): void {
            $this->expectExceptionMessage('explicit isolated profile and destructive-test consent required');
            $this->profileApplication($flag);
        });
    }

    public function test_configuration_or_environment_cannot_enable_the_probe(): void
    {
        $environment = ['HORUS_PG_TEST_CONFIRM' => ''] + $this->safeEnvironment();
        $this->withEnvironment($environment, function (): void {
            $config = $this->safeConfig();
            $config[TestDatabaseSafety::READ_ONLY_PROBE] = true;
            $config['pgsql-testing-database'] = $config['database'];
            $app = $this->application($config);

            $this->assertFalse(TestDatabaseSafety::readOnlyProbe($app));
            $this->expectExceptionMessage('explicit isolated profile and destructive-test consent required');
            TestDatabaseSafety::install($app, $this->simulatedPreflight());
        });
    }

    private function profileApplication(mixed $flag): Application
    {
        $config = $this->safeConfig();
        $config['pgsql-testing-database'] = $config['database'];
        $app = $this->application($config);
        if ($flag !== null) {
            $app->instance(TestDatabaseSafety::READ_ONLY_PROBE, $flag);
        }
        TestDatabaseSafety::install($app, $this->simulatedPreflight());

        return $app;
    }

    private function probeConnector(PDO $pdo): GuardedPostgresConnector
    {
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->expects($this->once())->method('connect')->with($this->callback(
            fn ($config) => $config['server_options']['default_transaction_read_only'] === 'on',
        ))->willReturn($pdo);

        return new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver, true);
    }

    /** @param list<array<string, mixed>> $rows */
    private function pdo(array $rows): PDO&MockObject
    {
        $statements = array_map(function (array $row): PDOStatement {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetch')->willReturn($row);

            return $statement;
        }, $rows);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(count($rows)))->method('query')->willReturnOnConsecutiveCalls(...$statements);

        return $pdo;
    }

    /** @return array<string, string> */
    private function clusterIdentity(): array
    {
        return ['cluster_name' => 'horus_pgt_55433', 'system_identifier' => '7702263743609677164',
            'transaction_read_only' => 'on', 'default_transaction_read_only' => 'on'];
    }
}
