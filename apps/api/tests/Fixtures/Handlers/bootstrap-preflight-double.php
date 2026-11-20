<?php

declare(strict_types=1);

namespace Tests\Fixtures\Handlers;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** Test-only substitute: no worker, profile, credentials or database connection. */
final class PreflightDouble
{
    public static bool $verified = false;

    public function verify(): void
    {
        if (getenv('HORUS_HANDLER_FIXTURE_REJECT') === '1') {
            throw new \RuntimeException('Fixture preflight refused before any test.');
        }
        self::$verified = true;
    }
}

// Only this explicit fixture bootstrap replaces the class. Production has no bypass switch.
class_alias(PreflightDouble::class, 'App\Support\Testing\PostgresTestPreflight');
require dirname(__DIR__, 2).'/bootstrap-pgsql.php';
