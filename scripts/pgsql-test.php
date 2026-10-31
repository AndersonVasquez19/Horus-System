<?php

declare(strict_types=1);

use App\Support\Testing\GuardedPostgresConnector;
use App\Support\Testing\PostgresTestArguments;
use App\Support\Testing\PostgresTestGuard;
use App\Support\Testing\PostgresTestPreflight;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $action = $argv[1] ?? 'check';
    $commands = ['migrate:status', 'migrate', 'migrate:rollback', 'migrate:fresh', 'db:wipe', 'db:seed'];
    if (! in_array($action, ['check', 'probe', 'test', 'artisan'], true)
        || (in_array($action, ['check', 'probe'], true) && count($argv) !== 2)
        || ($action === 'artisan' && (count($argv) !== 3 || ! in_array($argv[2], $commands, true)))) {
        throw new RuntimeException('Usage: php scripts/pgsql-test.php check|probe|test [--filter=...] [--stop-on-error|--stop-on-failure]|artisan <approved-command>');
    }
    // Validated before Laravel boots: a rejected option never reaches the database.
    $phpunitOptions = $action === 'test' ? PostgresTestArguments::phpunitOptions(array_values(array_slice($argv, 2))) : [];

    // Select the isolated env file before Laravel loads any configuration.
    foreach ([$_ENV['HORUS_PG_TEST_PROFILE'] ?? null, $_SERVER['HORUS_PG_TEST_PROFILE'] ?? null,
        getenv('HORUS_PG_TEST_PROFILE') === false ? null : getenv('HORUS_PG_TEST_PROFILE')] as $source) {
        if ($source !== null && $source !== '1') {
            throw new RuntimeException('Conflicting PostgreSQL profile selection.');
        }
    }
    putenv('HORUS_PG_TEST_PROFILE=1');
    $_ENV['HORUS_PG_TEST_PROFILE'] = $_SERVER['HORUS_PG_TEST_PROFILE'] = '1';

    if ($action === 'test') {
        // The worker validates in read-only mode before PHPUnit exists. Direct PHPUnit also checks.
        exit((new PostgresTestPreflight)->runTests($phpunitOptions));
    }

    $app = require dirname(__DIR__).'/bootstrap/app.php';
    if ($action === 'probe') {
        // Read-only probe: no destructive consent; the guarded connector never enables writes.
        $app->instance(TestDatabaseSafety::READ_ONLY_PROBE, true);
    }
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $connection = $app->make('db')->connection('pgsql');
    $connection->getPdo(); // Independent marker and actual server identity, before dispatch.

    if ($action === 'probe') {
        $connector = $app->make('db.connector.pgsql');
        if (! $connector instanceof GuardedPostgresConnector || ! $connector->isReadOnlyProbe()) {
            throw new RuntimeException('Horus PostgreSQL test safety: read-only probe connector not installed.');
        }
        // Re-read on the same session after Laravel's connection setup; SELECT only.
        $session = (array) $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database, current_user AS username,
                inet_server_port()::text AS port, current_setting('cluster_name') AS cluster_name,
                (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
                current_setting('transaction_read_only') AS transaction_read_only,
                current_setting('search_path') AS search_path, current_setting('TimeZone') AS timezone
            SQL);
        (new PostgresTestGuard)->assertClusterIdentity($session + ['default_transaction_read_only' => 'on']);
        if ($session['transaction_read_only'] !== 'on' || ! in_array($session['search_path'], ['public', '"public"'], true)
            || $session['timezone'] !== 'UTC') {
            throw new RuntimeException('Horus PostgreSQL test safety: probe session settings mismatch.');
        }
        foreach ($session as $key => $value) {
            echo str_pad((string) $key, 22).(string) $value.PHP_EOL;
        }
        echo "Read-only probe passed: marker, least privilege and cluster identity verified; no writes enabled.\n";
        exit(0);
    }

    if ($action === 'check') {
        // Fixed allowlisted identity, no credentials and no DDL/DML.
        echo 'Authorized isolated PostgreSQL session: '.$connection->getDatabaseName().' on '
            .PostgresTestGuard::HOST.':'.PostgresTestGuard::PORT."; no DDL/DML executed.\n";
        exit(0);
    }
    if ($action === 'artisan') {
        $status = $kernel->call($argv[2]);
        echo $kernel->output();
        exit($status);
    }

} catch (Throwable $error) {
    $message = str_starts_with($error->getMessage(), 'Horus PostgreSQL test safety:')
        ? $error->getMessage() : 'Horus PostgreSQL test safety: command refused or failed; inspect the isolated setup without exposing secrets.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
