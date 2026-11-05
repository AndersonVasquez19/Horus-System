<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** A read-only worker validates PostgreSQL without booting Laravel in the PHPUnit process. */
final class PostgresTestPreflight
{
    public const PROTOCOL = 'horus-pg-preflight-v1';

    /** @param (Closure(list<string>): Process)|null $processFactory */
    public function __construct(private readonly ?Closure $processFactory = null) {}

    /** @return array<string, string> */
    private static function expectedIdentity(): array
    {
        return ['server_version' => '18.6', 'system_identifier' => PostgresTestGuard::SYSTEM_IDENTIFIER,
            'cluster_name' => PostgresTestGuard::CLUSTER_NAME, 'host' => PostgresTestGuard::HOST,
            'port' => PostgresTestGuard::PORT, 'database' => 'horus_l2_test',
            'username' => PostgresTestGuard::TARGETS['horus_l2_test'],
            'transaction_read_only' => 'on', 'default_transaction_read_only' => 'on'];
    }

    /** @param array<string, mixed> $identity */
    private static function assertIdentity(array $identity): void
    {
        if (count($identity) !== count(self::expectedIdentity())) {
            (new PostgresTestGuard)->reject('preflight identity fields mismatch.');
        }
        foreach (self::expectedIdentity() as $key => $value) {
            if (($identity[$key] ?? null) !== $value) {
                (new PostgresTestGuard)->reject('preflight identity mismatch: '.$key.'.');
            }
        }
    }

    /** @param list<string> $command */
    private function process(array $command): Process
    {
        return $this->processFactory !== null
            ? ($this->processFactory)($command)
            : new Process($command, dirname(__DIR__, 3));
    }

    public function verify(): void
    {
        try {
            $process = $this->process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/pgsql-test-preflight.php']);
            $process->setTimeout(30);
            $exit = $process->run();
        } catch (Throwable) {
            // Never forward native exceptions: they can contain environment or connection details.
            throw new RuntimeException('Horus PostgreSQL test safety: preflight process failed; PHPUnit refused.');
        }

        // Retrieve streams separately. Do not echo a worker's output, including malformed output.
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        if ($exit !== 0 || $stderr !== '') {
            (new PostgresTestGuard)->reject('preflight refused (exit '.$exit.'); PHPUnit refused.');
        }

        $result = json_decode($stdout, true);
        if (! is_array($result) || array_keys($result) !== ['protocol', 'identity']
            || $result['protocol'] !== self::PROTOCOL || ! is_array($result['identity'])) {
            (new PostgresTestGuard)->reject('preflight result unreadable; PHPUnit refused.');
        }
        self::assertIdentity($result['identity']);
    }

    /** @param list<string> $arguments */
    public function runTests(array $arguments): int
    {
        $options = PostgresTestArguments::phpunitOptions($arguments);
        $this->verify();
        $process = $this->process([PHP_BINARY, dirname(__DIR__, 3).'/vendor/bin/phpunit',
            '--configuration', dirname(__DIR__, 3).'/phpunit.pgsql.xml', ...$options]);
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $output): void {
            echo $output;
        });
    }

    /** Install the same guards, but never give this worker a connector capable of enabling writes. */
    public static function prepare(Application $app): void
    {
        $app->instance(TestDatabaseSafety::READ_ONLY_PROBE, true);
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $connection = TestDatabaseSafety::postgresConfiguration($app);
            if ($connection['database'] !== 'horus_l2_test'
                || Env::get('HORUS_PG_TEST_CONFIRM') !== PostgresTestGuard::CONFIRMATION) {
                (new PostgresTestGuard)->reject('preflight requires the primary test database and destructive-test consent.');
            }
        });
    }

    /** @return array<string, mixed> */
    public static function inspect(Application $app): array
    {
        self::prepare($app);
        $app->make(Kernel::class)->bootstrap();
        $connector = self::connector($app);
        if (! $connector instanceof GuardedPostgresConnector || ! $connector->isReadOnlyProbe()) {
            (new PostgresTestGuard)->reject('preflight requires the read-only guarded connector.');
        }
        $connection = $app->make('db')->connection('pgsql');
        $connection->getPdo(); // Marker, least privilege and physical identity: unchanged guarded path.
        $identity = (array) $connection->selectOne(<<<'SQL'
            SELECT current_setting('server_version') AS server_version,
                (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
                current_setting('cluster_name') AS cluster_name,
                host(inet_server_addr()) AS host, inet_server_port()::text AS port,
                current_database() AS database, current_user AS username,
                current_setting('transaction_read_only') AS transaction_read_only,
                current_setting('default_transaction_read_only') AS default_transaction_read_only
            SQL);
        self::assertIdentity($identity);

        return $identity;
    }

    private static function connector(Application $app): ConnectorInterface
    {
        return $app->make('db.connector.pgsql');
    }
}
