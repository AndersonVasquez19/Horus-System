<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\ConcurrentTestSession;
use App\Support\Testing\GuardedPostgresConnection;
use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\LostConnectionException;
use Illuminate\Foundation\Application;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgresSafetyFixtures;

/**
 * Segunda sesión de las pruebas de concurrencia (L2.2-C), solo con dobles: nunca se abre un
 * socket. El conector interno es un mock; el PDO, también.
 */
class ConcurrentTestSessionTest extends TestCase
{
    use PostgresSafetyFixtures;

    public function test_profile_authorizes_an_independent_second_session_through_the_guarded_connector(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $pdo = $this->verifiedPdo();
            $inner = $this->createMock(ConnectorInterface::class);
            $inner->expects($this->once())->method('connect')->willReturnCallback(function (array $config) use ($pdo): PDO {
                // Misma configuración validada, en modo solo lectura hasta verificar la identidad.
                $this->assertSame('horus_l2_test', $config['database']);
                $this->assertSame('127.0.0.1', $config['host']);
                $this->assertSame('55432', $config['port']);
                $this->assertSame('horus_l2_test_runner', $config['username']);
                $this->assertSame('on', $config['server_options']['default_transaction_read_only']);

                return $pdo;
            });
            $app = $this->profileApplication($inner);
            $principal = $app->make('db')->connection('pgsql');

            $sesion = ConcurrentTestSession::open($app);

            $this->assertInstanceOf(GuardedPostgresConnection::class, $sesion);
            $this->assertNotSame($principal, $sesion);
            $this->assertSame($this->activeConnection(), $sesion->getConfig());
            // No se registra ningún nombre nuevo ni se altera la configuración global.
            $this->assertSame(['pgsql'], array_keys($app->make('db')->getConnections()));
            $this->assertSame(['pgsql'], array_keys($app->make('config')->get('database.connections')));
            $this->assertSame($pdo, $sesion->getPdo());
        });
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function alteredConfigurations(): iterable
    {
        yield 'otro host' => ['host', '10.0.0.5', 'unexpected connection setting: host'];
        yield 'otro puerto' => ['port', '5432', 'unexpected connection setting: port'];
        yield 'base habitual' => ['database', 'horus_group', 'database is not on the fixed test allowlist'];
        yield 'otro usuario' => ['username', 'postgres', 'unexpected connection setting: username'];
        yield 'opción añadida' => ['options', [PDO::ATTR_PERSISTENT => true], 'alternate connection options are forbidden'];
        yield 'otro nombre lógico' => ['name', 'pgsql_concurrent', 'alternate connection options are forbidden'];
        yield 'url' => ['url', 'pgsql://127.0.0.1:55432/horus_l2_test', 'DB_URL is forbidden'];
    }

    #[DataProvider('alteredConfigurations')]
    public function test_altered_configuration_is_rejected_before_any_connection(string $key, mixed $value, string $reason): void
    {
        $this->withEnvironment($this->safeEnvironment(), function () use ($key, $value, $reason): void {
            $inner = $this->createMock(ConnectorInterface::class);
            $inner->expects($this->never())->method('connect');
            $app = $this->profileApplication($inner);
            $app->make('config')->set("database.connections.pgsql.{$key}", $value);

            $this->expectExceptionMessage($reason);
            ConcurrentTestSession::open($app);
        });
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function alteredEnvironment(): iterable
    {
        yield 'otro host' => ['DB_HOST', '10.0.0.5', 'environment/configuration mismatch: DB_HOST'];
        yield 'base habitual' => ['DB_DATABASE', 'horus_group', 'environment/configuration mismatch: DB_DATABASE'];
        yield 'otro usuario' => ['DB_USERNAME', 'postgres', 'environment/configuration mismatch: DB_USERNAME'];
        yield 'sin consentimiento' => ['HORUS_PG_TEST_CONFIRM', '', 'explicit isolated profile and destructive-test consent required'];
        yield 'destino secundario' => ['PGHOST', 'produccion.example.invalid', 'secondary destinations and external credentials are forbidden'];
    }

    #[DataProvider('alteredEnvironment')]
    public function test_environment_changed_after_installation_is_rejected_before_any_connection(string $key, string $value, string $reason): void
    {
        $this->withEnvironment($this->safeEnvironment(), function () use ($key, $value, $reason): void {
            $inner = $this->createMock(ConnectorInterface::class);
            $inner->expects($this->never())->method('connect');
            $app = $this->profileApplication($inner);

            $this->withEnvironment([$key => $value] + $this->safeEnvironment(), function () use ($app, $reason): void {
                $this->expectExceptionMessage($reason);
                ConcurrentTestSession::open($app);
            });
        });
    }

    public function test_outside_the_isolated_profile_no_second_session_is_possible(): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'HORUS_PG_TEST_PROFILE' => ''], function (): void {
            $config = $this->safeConfig();
            $config['database']['default'] = 'sqlite';
            $config['database']['connections'] = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']];
            $app = $this->application($config);
            TestDatabaseSafety::install($app);

            $this->expectExceptionMessage('a second session requires the isolated PostgreSQL profile');
            ConcurrentTestSession::open($app);
        });
    }

    public function test_an_unguarded_connector_is_refused(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $app = $this->profileApplication($this->createMock(ConnectorInterface::class));
            $plano = $this->createMock(ConnectorInterface::class);
            $plano->expects($this->never())->method('connect');
            $app->instance('db.connector.pgsql', $plano);

            $this->expectExceptionMessage('a second session requires the guarded factory and connector');
            ConcurrentTestSession::open($app);
        });
    }

    public function test_identity_mismatch_rejects_the_session_without_enabling_writes(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $identidad = ['marker' => 'otra-base'] + $this->safeIdentity();
            $pdo = $this->pdoWithIdentity($identidad);
            // El único punto que habilita escrituras nunca se alcanza tras un rechazo.
            $pdo->expects($this->never())->method('exec');
            $inner = $this->createMock(ConnectorInterface::class);
            $inner->method('connect')->willReturn($pdo);
            $sesion = ConcurrentTestSession::open($this->profileApplication($inner));

            try {
                $sesion->getPdo();
                $this->fail('La identidad del servidor no coincide; la sesión debía rechazarse.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('server identity or independent authorization mismatch: marker', $error->getMessage());
            }
        });
    }

    public function test_close_rolls_back_open_transactions_and_disconnects(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $sentencias = [];
            $pdo = $this->pdoWithIdentity($this->safeIdentity());
            $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$sentencias): int {
                $sentencias[] = $sql;

                return 0;
            });
            $pdo->method('beginTransaction')->willReturn(true);
            $pdo->method('inTransaction')->willReturn(true);
            $pdo->expects($this->once())->method('rollBack')->willReturn(true);
            $pdo->expects($this->never())->method('commit');
            $inner = $this->createMock(ConnectorInterface::class);
            $inner->method('connect')->willReturn($pdo);
            $sesion = ConcurrentTestSession::open($this->profileApplication($inner));

            $sesion->beginTransaction();
            $sesion->beginTransaction(); // Savepoint anidado: también se descarta.
            $this->assertSame(2, $sesion->transactionLevel());

            ConcurrentTestSession::close($sesion);

            $this->assertSame([
                'SET default_transaction_read_only = off',
                'SAVEPOINT trans2',
                'ROLLBACK TO SAVEPOINT trans2',
            ], $sentencias);
            $this->assertSame(0, $sesion->transactionLevel());
            $this->assertNull($sesion->getRawPdo());
        });
    }

    public function test_a_closed_session_cannot_reconnect_by_any_path(): void
    {
        $this->withEnvironment($this->safeEnvironment(), function (): void {
            $inner = $this->createMock(ConnectorInterface::class);
            // Una única apertura: tras close() el conector no vuelve a invocarse.
            $inner->expects($this->once())->method('connect')->willReturn($this->verifiedPdo());
            $sesion = ConcurrentTestSession::open($this->profileApplication($inner));
            $sesion->getPdo();

            ConcurrentTestSession::close($sesion);

            $this->expectException(LostConnectionException::class);
            $sesion->select('select 1');
        });
    }

    /**
     * Aplicación con el perfil instalado y el conector protegido real, cuyo conector interno es un doble.
     */
    private function profileApplication(ConnectorInterface $inner): Application
    {
        $config = $this->safeConfig();
        $config['pgsql-testing-database'] = $config['database'];
        $app = $this->application($config);
        TestDatabaseSafety::install($app);
        $app->bind('db.connector.pgsql', fn () => new GuardedPostgresConnector(
            fn (): array => TestDatabaseSafety::postgresConfiguration($app),
            $inner,
        ));

        return $app;
    }

    private function verifiedPdo(): PDO&MockObject
    {
        $pdo = $this->pdoWithIdentity($this->safeIdentity());
        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->assertSame('SET default_transaction_read_only = off', $sql);

            return 0;
        });

        return $pdo;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function pdoWithIdentity(array $identity): PDO&MockObject
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($identity);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($statement);

        return $pdo;
    }
}
