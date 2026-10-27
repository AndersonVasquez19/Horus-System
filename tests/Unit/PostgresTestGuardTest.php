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
        $pdo->expects($this->once())->method('query')->with($this->callback(
            fn ($sql) => str_contains($sql, 'current_database()') && str_contains($sql, 'database_oid')
                && str_contains($sql, 'has_database_privilege') && str_contains($sql, 'writable_marker'),
        ))->willReturn($statement);
        $pdo->expects($this->once())->method('exec')->with('SET default_transaction_read_only = off')->willReturn(0);
        $this->assertSame($pdo, $this->mockReadOnlyConnector($pdo)->connect(
            $this->activeConnection(),
        ));
    }

    public function test_nonexistent_database_or_connection_failure_is_sanitized(): void
    {
        $driver = $this->createMock(ConnectorInterface::class);
        $driver->method('connect')->willThrowException(new PDOException('password=DO_NOT_EXPOSE nonexistent database'));
        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver);
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
        $connector = new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver);
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

        return new GuardedPostgresConnector(fn () => $this->safeConfig()['database']['connections']['pgsql'], $driver);
    }
}
