<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\PostgresTestGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgresSafetyFixtures;

class PostgresTestGuardTest extends TestCase
{
    use PostgresSafetyFixtures;

    public function test_both_fixed_targets_require_their_own_dedicated_role(): void
    {
        foreach (PostgresTestGuard::TARGETS as $database => $role) {
            $config = $this->safeConfig();
            $environment = $this->safeEnvironment();
            $config['database']['connections']['pgsql']['database'] = $environment['DB_DATABASE'] = $database;
            $config['database']['connections']['pgsql']['username'] = $environment['DB_USERNAME'] = $role;
            $connection = (new PostgresTestGuard)->assertConfiguration($config, $environment, false);
            $identity = array_replace($this->safeIdentity(), ['database' => $database, 'username' => $role]);
            $this->assertSame('authorized', (new PostgresTestGuard)->authorize($connection, fn () => $identity, fn () => 'authorized'));
        }
    }

    public function test_only_the_independent_loopback_destination_is_allowed(): void
    {
        // Literal values on purpose: a silent constant change must break this test.
        $this->assertSame('127.0.0.1', PostgresTestGuard::HOST);
        $this->assertSame('55433', PostgresTestGuard::PORT);
        $this->assertSame(['55432'], PostgresTestGuard::RETIRED_PORTS);
        $this->assertSame([
            'horus_l2_test' => 'horus_l2_test_runner',
            'horus_l2_restore_test' => 'horus_l2_restore_test_runner',
        ], PostgresTestGuard::TARGETS);

        $guard = new PostgresTestGuard;
        $connection = $guard->assertConfiguration($this->safeConfig(), $this->safeEnvironment(), false);
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('55433', $connection['port']);
        $this->assertSame('authorized', $guard->authorize($connection, fn () => $this->safeIdentity(), fn () => 'authorized'));
    }

    public static function s7Manipulations(): iterable
    {
        yield 'configuration only' => [['port' => '55432'], []];
        yield 'environment only' => [[], ['DB_PORT' => '55432']];
        yield 'configuration and environment consistent' => [['port' => '55432'], ['DB_PORT' => '55432']];
        yield 'integer port' => [['port' => 55432], ['DB_PORT' => '55432']];
        yield 'padded port' => [['port' => ' 55432 '], ['DB_PORT' => ' 55432']];
        yield 'restore target on S7' => [['port' => '55432', 'database' => 'horus_l2_restore_test',
            'username' => 'horus_l2_restore_test_runner'], ['DB_PORT' => '55432',
                'DB_DATABASE' => 'horus_l2_restore_test', 'DB_USERNAME' => 'horus_l2_restore_test_runner']];
    }

    #[DataProvider('s7Manipulations')]
    public function test_a_manipulated_configuration_cannot_reach_s7(array $connection, array $environment): void
    {
        $config = $this->safeConfig();
        $config['database']['connections']['pgsql'] = array_replace($config['database']['connections']['pgsql'], $connection);
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->expects($this->never())->method('connect');
        $probes = 0;
        try {
            $guard = new PostgresTestGuard;
            $checked = $guard->assertConfiguration($config, array_replace($this->safeEnvironment(), $environment), false);
            $guard->authorize($checked, function () use (&$probes) {
                $probes++;

                return $this->safeIdentity();
            }, fn () => $driver->connect([]));
            $this->fail('S7 destination accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Horus PostgreSQL test safety: retired S7 port is refused.', $error->getMessage());
        }
        $this->assertSame(0, $probes);
    }

    public function test_guarded_connector_never_opens_a_socket_towards_s7(): void
    {
        $config = $this->safeConfig()['database']['connections']['pgsql'];
        $config['port'] = '55432';
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->expects($this->never())->method('connect');
        $connector = new GuardedPostgresConnector(
            fn () => (new PostgresTestGuard)->assertConfiguration(
                array_replace_recursive($this->safeConfig(), ['database' => ['connections' => ['pgsql' => $config]]]),
                ['DB_PORT' => '55432'] + $this->safeEnvironment(), false),
            $driver,
        );
        $this->expectExceptionMessage('retired S7 port is refused');
        $connector->connect(PostgresTestGuard::laravelConnection($config));
    }

    public function test_server_reporting_the_s7_port_never_enables_writes(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(array_replace($this->safeIdentity(), ['server_port' => '55432']));
        $pdo->expects($this->once())->method('query')->willReturn($statement);
        $this->expectExceptionMessage('server reports the retired S7 port; no writes authorized');
        $this->mockReadOnlyConnector($pdo)->connect($this->activeConnection());
    }

    public static function unsafeDestinations(): iterable
    {
        yield 'localhost name' => [['host' => 'localhost'], ['DB_HOST' => 'localhost'], 'unexpected connection setting: host'];
        yield 'IPv6 loopback' => [['host' => '::1'], ['DB_HOST' => '::1'], 'unexpected connection setting: host'];
        yield 'all interfaces' => [['host' => '0.0.0.0'], ['DB_HOST' => '0.0.0.0'], 'unexpected connection setting: host'];
        yield 'daily port' => [['port' => '5432'], ['DB_PORT' => '5432'], 'unexpected connection setting: port'];
        yield 'alternative port' => [['port' => '55434'], ['DB_PORT' => '55434'], 'unexpected connection setting: port'];
        yield 'integer allowed port' => [['port' => 55433], [], 'unexpected connection setting: port'];
        yield 'environment port mismatch' => [[], ['DB_PORT' => '55434'], 'environment/configuration mismatch: DB_PORT'];
        yield 'S7 port injected in host' => [['host' => '127.0.0.1 port=55432'], ['DB_HOST' => '127.0.0.1 port=55432'], 'unexpected connection setting: host'];
        yield 'S7 port injected in database' => [['database' => 'horus_l2_test port=55432'], ['DB_DATABASE' => 'horus_l2_test port=55432'], 'database is not on the fixed test allowlist'];
        yield 'libpq port override to S7' => [[], ['PGPORT' => '55432'], 'secondary destinations and external credentials are forbidden'];
        yield 'daily database' => [['database' => 'horus_group'], ['DB_DATABASE' => 'horus_group'], 'database is not on the fixed test allowlist'];
        yield 'rehearsal database' => [['database' => 'horus_group_ensayo'], ['DB_DATABASE' => 'horus_group_ensayo'], 'database is not on the fixed test allowlist'];
        yield 'crossed runner' => [['username' => 'horus_l2_restore_test_runner'], ['DB_USERNAME' => 'horus_l2_restore_test_runner'], 'unexpected connection setting: username'];
        yield 'missing profile' => [[], ['HORUS_PG_TEST_PROFILE' => ''], 'explicit isolated profile and destructive-test consent required'];
        yield 'wrong profile value' => [[], ['HORUS_PG_TEST_PROFILE' => 'true'], 'explicit isolated profile and destructive-test consent required'];
        yield 'missing consent' => [[], ['HORUS_PG_TEST_CONFIRM' => ''], 'explicit isolated profile and destructive-test consent required'];
        yield 'approximate consent' => [[], ['HORUS_PG_TEST_CONFIRM' => 'authorize_horus_l2_destructive_tests'], 'explicit isolated profile and destructive-test consent required'];
    }

    #[DataProvider('unsafeDestinations')]
    public function test_unsafe_destination_is_rejected_with_its_specific_reason(array $connection, array $environment, string $reason): void
    {
        $config = $this->safeConfig();
        $config['database']['connections']['pgsql'] = array_replace($config['database']['connections']['pgsql'], $connection);
        $this->expectExceptionMessage($reason);
        (new PostgresTestGuard)->assertConfiguration($config, array_replace($this->safeEnvironment(), $environment), false);
    }

    public static function unsafeConfigurations(): iterable
    {
        yield 'development database' => ['connection', 'database', 'horus_group'];
        yield 'unknown test name' => ['connection', 'database', 'horus_other_test'];
        yield 'remote host' => ['connection', 'host', '192.0.2.1'];
        yield 'multi host' => ['connection', 'host', ['127.0.0.1', '192.0.2.1']];
        yield 'daily development port' => ['connection', 'port', '5432'];
        yield 'privileged user' => ['connection', 'username', 'postgres'];
        yield 'wrong driver' => ['connection', 'driver', 'sqlite'];
        yield 'connection URL' => ['connection', 'url', 'pgsql://fictional:secret@invalid/horus_group'];
        yield 'read replica' => ['connection', 'read', ['host' => '192.0.2.1']];
        yield 'write replica' => ['connection', 'write', ['database' => 'horus_group']];
        yield 'database tunnel' => ['connection', 'connect_via_database', 'horus_group'];
        yield 'PDO override' => ['connection', 'options', [PDO::ATTR_PERSISTENT => true]];
        yield 'server options override' => ['connection', 'server_options', ['search_path' => 'other']];
        yield 'search path' => ['connection', 'search_path', 'public,other'];
        yield 'empty password' => ['connection', 'password', ''];
        yield 'environment URL' => ['environment', 'DB_URL', 'pgsql://invalid'];
        yield 'environment mismatch' => ['environment', 'DB_DATABASE', 'horus_group'];
        yield 'missing profile' => ['environment', 'HORUS_PG_TEST_PROFILE', ''];
        yield 'missing consent' => ['environment', 'HORUS_PG_TEST_CONFIRM', ''];
        yield 'wrong environment' => ['environment', 'APP_ENV', 'local'];
        yield 'secondary cache' => ['environment', 'DB_CACHE_CONNECTION', 'other'];
        yield 'secondary queue' => ['environment', 'DB_QUEUE_CONNECTION', 'other'];
        yield 'secondary session' => ['environment', 'SESSION_CONNECTION', 'other'];
        yield 'external Redis' => ['environment', 'REDIS_URL', 'redis://invalid'];
        yield 'external OpenAI' => ['environment', 'OPENAI_API_KEY', 'fictional-key'];
        yield 'external mail' => ['environment', 'RESEND_API_KEY', 'fictional-key'];
        yield 'libpq service override' => ['environment', 'PGSERVICE', 'daily'];
        yield 'libpq address override' => ['environment', 'PGHOSTADDR', '192.0.2.1'];
        yield 'libpq options override' => ['environment', 'PGOPTIONS', '-c search_path=other'];
        yield 'cached configuration' => ['cached', '', true];
        yield 'effective local environment' => ['app', 'env', 'local'];
        yield 'persistent cache' => ['cache', 'default', 'database'];
        yield 'persistent sessions' => ['session', 'driver', 'database'];
        yield 'persistent queue' => ['queue', 'default', 'database'];
        yield 'real mail' => ['mail', 'default', 'smtp'];
        yield 'broadcast service' => ['broadcasting', 'default', 'pusher'];
        yield 'secondary connection' => ['secondary', '', ['driver' => 'pgsql', 'database' => 'horus_group']];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_rejection_precedes_probe_and_migration(string $section, string $key, mixed $value): void
    {
        $config = $this->safeConfig();
        $environment = $this->safeEnvironment();
        $cached = false;
        if ($section === 'connection') {
            $config['database']['connections']['pgsql'][$key] = $value;
        } elseif ($section === 'environment') {
            $environment[$key] = $value;
        } elseif ($section === 'cached') {
            $cached = true;
        } elseif ($section === 'secondary') {
            $config['database']['connections']['other'] = $value;
        } else {
            $config[$section][$key] = $value;
        }

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');
        $probes = 0;
        $guard = new PostgresTestGuard;
        try {
            $connection = $guard->assertConfiguration($config, $environment, $cached);
            $guard->authorize($connection, function () use (&$probes) {
                $probes++;

                return $this->safeIdentity();
            }, fn () => $kernel->call('migrate:fresh'));
            $this->fail('Unsafe configuration accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('Horus PostgreSQL test safety:', $error->getMessage());
            $this->assertStringNotContainsString('fictional-test-password', $error->getMessage());
        }
        $this->assertSame(0, $probes);
    }

    public static function unsafeIdentities(): iterable
    {
        yield 'wrong database' => ['database', 'horus_group'];
        yield 'wrong role' => ['username', 'postgres'];
        yield 'wrong server' => ['server_address', '192.0.2.1'];
        yield 'wrong port' => ['server_port', '5432'];
        yield 'S7 port' => ['server_port', '55432'];
        yield 'alternative port' => ['server_port', '55434'];
        yield 'IPv6 server' => ['server_address', '::1'];
        yield 'wrong search path' => ['search_path', 'public, other'];
        yield 'not read only' => ['read_only', 'off'];
        yield 'wrong timezone' => ['timezone', 'America/Lima'];
        yield 'wrong isolation' => ['isolation', 'repeatable read'];
        yield 'missing independent marker' => ['marker', null];
        yield 'wrong marker' => ['marker', 'development'];
        yield 'privileged role' => ['unsafe_role', 1];
        yield 'assumable role' => ['role_memberships', 1];
        yield 'access to another database' => ['other_database_access', 1];
        yield 'editable marker' => ['writable_marker', 1];
        yield 'missing privilege evidence' => ['unsafe_role', null];
    }

    #[DataProvider('unsafeIdentities')]
    public function test_identity_rejection_never_enables_writes(string $key, mixed $value): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(array_replace($this->safeIdentity(), [$key => $value]));
        $pdo->expects($this->once())->method('query')->willReturn($statement);
        $connector = $this->mockReadOnlyConnector($pdo);
        $this->expectException(RuntimeException::class);
        $connector->connect($this->activeConnection());
    }

    public function test_exact_probed_pdo_is_returned_and_only_then_write_mode_is_enabled(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($this->safeIdentity());
        $order = [];
        $pdo->expects($this->exactly(2))->method('query')->willReturnCallback(function (string $sql) use (&$order, $statement): PDOStatement {
            $order[] = str_contains($sql, 'pg_control_system') ? 'cluster' : 'identity';
            if ($order === ['identity']) {
                $this->assertTrue(str_contains($sql, 'current_database()') && str_contains($sql, 'database_oid')
                    && str_contains($sql, 'has_database_privilege') && str_contains($sql, 'writable_marker'));
            }

            return $statement;
        });
        $pdo->expects($this->once())->method('exec')->willReturnCallback(function (string $sql) use (&$order): int {
            // Writes are enabled only after the marker probe AND the physical cluster check.
            $this->assertSame(['identity', 'cluster'], $order);
            $this->assertSame('SET default_transaction_read_only = off', $sql);

            return 0;
        });
        $this->assertSame($pdo, $this->mockReadOnlyConnector($pdo)->connect(
            $this->activeConnection(),
        ));
    }

    public static function foreignClusters(): iterable
    {
        yield 'S7 cluster' => ['system_identifier', '7701505916098505688'];
        yield 'discarded first cluster' => ['system_identifier', '7702261264381348568'];
        yield 'daily-like identifier' => ['system_identifier', '1'];
        yield 'missing identifier' => ['system_identifier', null];
        yield 'S7 cluster name' => ['cluster_name', 'horus_l2_test'];
        yield 'empty cluster name' => ['cluster_name', ''];
        yield 'session already writable' => ['transaction_read_only', 'off'];
        yield 'default already writable' => ['default_transaction_read_only', 'off'];
    }

    #[DataProvider('foreignClusters')]
    public function test_write_path_refuses_any_other_cluster_before_enabling_writes(string $key, ?string $value): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(array_replace($this->safeIdentity(), [$key => $value]));
        $pdo->method('query')->willReturn($statement);
        $pdo->expects($this->never())->method('exec');
        $this->expectExceptionMessage('cluster identity or read-only state mismatch: '.$key);
        $this->mockReadOnlyConnector($pdo)->connect($this->activeConnection());
    }

    public function test_write_path_fails_closed_when_the_identifier_cannot_be_read(): void
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
            $this->mockReadOnlyConnector($pdo)->connect($this->activeConnection());
            $this->fail('Unreadable cluster identity accepted on the write path.');
        } catch (RuntimeException $error) {
            $this->assertSame('Horus PostgreSQL test safety: cluster identity probe failed; no writes authorized.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_environment_cannot_override_the_expected_cluster_identity(): void
    {
        $guard = new PostgresTestGuard;
        foreach (['HORUS_PG_TEST_SYSTEM_IDENTIFIER', 'HORUS_PG_TEST_CLUSTER_NAME', 'PG_SYSTEM_IDENTIFIER'] as $name) {
            $this->withEnvironment([$name => '7701505916098505688'] + $this->safeEnvironment(), function () use ($guard): void {
                try {
                    $guard->assertClusterIdentity(['system_identifier' => '7701505916098505688'] + $this->safeClusterIdentity());
                    $this->fail('Environment changed the expected cluster.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('cluster identity or read-only state mismatch: system_identifier', $error->getMessage());
                }
            });
        }
        $this->assertSame('7702263743609677164', PostgresTestGuard::SYSTEM_IDENTIFIER);
    }

    public function test_nonexistent_database_or_connection_failure_is_sanitized(): void
    {
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->method('connect')->willThrowException(new PDOException('password=DO_NOT_EXPOSE nonexistent database'));
        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver, preflight: $this->simulatedPreflight());
        try {
            $connector->connect($this->activeConnection());
            $this->fail('Expected a rejection.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('DO_NOT_EXPOSE', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_missing_marker_table_or_failed_probe_never_enables_writes(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new PDOException('missing marker; password=DO_NOT_EXPOSE'));
        $pdo->expects($this->never())->method('exec');
        $this->expectExceptionMessage('read-only identity probe failed');
        $this->mockReadOnlyConnector($pdo)->connect($this->activeConnection());
    }

    public function test_changed_connection_is_rejected_before_opening_a_socket(): void
    {
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->expects($this->never())->method('connect');
        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver, preflight: $this->simulatedPreflight());
        $config = $this->activeConnection();
        $config['database'] = 'horus_group';
        $this->expectExceptionMessage('connection changed');
        $connector->connect($config);
    }

    public static function markerColumnPrivileges(): iterable
    {
        yield 'column INSERT' => ['INSERT'];
        yield 'column UPDATE' => ['UPDATE'];
        yield 'column REFERENCES' => ['REFERENCES'];
    }

    #[DataProvider('markerColumnPrivileges')]
    public function test_column_privilege_sql_is_executed_and_excess_privileges_reject_writes(string $privilege): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(array_replace($this->safeIdentity(), ['writable_marker' => 1]));
        $pdo->expects($this->once())->method('query')->with($this->callback(function (string $sql) use ($privilege): bool {
            $this->assertMatchesRegularExpression(
                "/OR\\s+has_any_column_privilege\\(current_user,\\s*'horus_test_control\\.authorization',\\s*'".$privilege."'\\)/",
                $sql,
            );
            // Column checks must complement the existing effective privilege/ownership checks.
            $this->assertStringContainsString('has_table_privilege(current_user', $sql);
            $this->assertStringContainsString("has_schema_privilege(current_user, 'horus_test_control', 'CREATE')", $sql);
            $this->assertStringContainsString("pg_has_role(current_user, c.relowner, 'MEMBER')", $sql);
            $this->assertStringContainsString('FROM pg_auth_members WHERE member', $sql);

            return true;
        }))->willReturn($statement);

        $this->expectExceptionMessage('least-privilege or marker ownership check failed');
        $this->mockReadOnlyConnector($pdo)->connect($this->activeConnection());
    }

    private function mockReadOnlyConnector(PDO $pdo): GuardedPostgresConnector
    {
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->expects($this->once())->method('connect')->with($this->callback(
            fn ($config) => $config['server_options']['default_transaction_read_only'] === 'on'
                && $config['server_options']['statement_timeout'] === '5000',
        ))->willReturn($pdo);

        return new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver, preflight: $this->simulatedPreflight());
    }
}
