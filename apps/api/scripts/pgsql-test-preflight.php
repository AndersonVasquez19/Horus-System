<?php

declare(strict_types=1);

use App\Support\Testing\PostgresTestGuard;
use App\Support\Testing\PostgresTestPreflight;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    if (count($argv) !== 1) {
        (new PostgresTestGuard)->reject('preflight accepts no arguments.');
    }
    foreach ([$_ENV['HORUS_PG_TEST_PROFILE'] ?? null, $_SERVER['HORUS_PG_TEST_PROFILE'] ?? null,
        getenv('HORUS_PG_TEST_PROFILE') === false ? null : getenv('HORUS_PG_TEST_PROFILE')] as $source) {
        if ($source !== null && $source !== '1') {
            (new PostgresTestGuard)->reject('conflicting preflight profile.');
        }
    }
    putenv('HORUS_PG_TEST_PROFILE=1');
    $_ENV['HORUS_PG_TEST_PROFILE'] = $_SERVER['HORUS_PG_TEST_PROFILE'] = '1';

    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $identity = PostgresTestPreflight::inspect($app);
    echo json_encode(['protocol' => PostgresTestPreflight::PROTOCOL, 'identity' => $identity], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    $message = str_starts_with($error->getMessage(), 'Horus PostgreSQL test safety:')
        ? $error->getMessage() : 'Horus PostgreSQL test safety: read-only preflight failed; PHPUnit refused.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
