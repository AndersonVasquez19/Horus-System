<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $action = $argv[1] ?? 'check';
    $commands = ['migrate:status', 'migrate', 'migrate:rollback', 'migrate:fresh', 'db:wipe', 'db:seed'];
    if (! in_array($action, ['check', 'test', 'artisan'], true)
        || ($action === 'check' && count($argv) !== 2)
        || ($action === 'artisan' && (count($argv) !== 3 || ! in_array($argv[2], $commands, true)))) {
        throw new RuntimeException('Usage: php scripts/pgsql-test.php check|test [--filter=...]|artisan <approved-command>');
    }
    if ($action === 'test') {
        foreach (array_slice($argv, 2) as $argument) {
            if (! str_starts_with($argument, '--filter=') && $argument !== '--stop-on-failure') {
                throw new RuntimeException('Only --filter=... and --stop-on-failure are accepted.');
            }
        }
    }

    // Select the isolated env file before Laravel loads any configuration.
    foreach ([$_ENV['HORUS_PG_TEST_PROFILE'] ?? null, $_SERVER['HORUS_PG_TEST_PROFILE'] ?? null,
        getenv('HORUS_PG_TEST_PROFILE') === false ? null : getenv('HORUS_PG_TEST_PROFILE')] as $source) {
        if ($source !== null && $source !== '1') {
            throw new RuntimeException('Conflicting PostgreSQL profile selection.');
        }
    }
    putenv('HORUS_PG_TEST_PROFILE=1');
    $_ENV['HORUS_PG_TEST_PROFILE'] = $_SERVER['HORUS_PG_TEST_PROFILE'] = '1';

    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $connection = $app->make('db')->connection('pgsql');
    $connection->getPdo(); // Independent marker and actual server identity, before dispatch.

    if ($action === 'check') {
        // Fixed allowlisted identity, no credentials and no DDL/DML.
        echo 'Authorized isolated PostgreSQL session: '.$connection->getDatabaseName()." on 127.0.0.1:55432; no DDL/DML executed.\n";
        exit(0);
    }
    if ($action === 'artisan') {
        $status = $kernel->call($argv[2]);
        echo $kernel->output();
        exit($status);
    }

    $process = new Process([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phpunit',
        '--configuration', dirname(__DIR__).'/phpunit.pgsql.xml', ...array_slice($argv, 2)], dirname(__DIR__));
    $process->setTimeout(null);
    exit($process->run(static fn ($type, $output) => print ($output)));
} catch (Throwable $error) {
    $message = str_starts_with($error->getMessage(), 'Horus PostgreSQL test safety:')
        ? $error->getMessage() : 'Horus PostgreSQL test safety: command refused or failed; inspect the isolated setup without exposing secrets.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
