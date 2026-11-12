<?php

declare(strict_types=1);

namespace Tests\Fixtures\Preflight;

use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgresSafetyFixtures;

/** Executed only by the local boundary regression with SQLite XML and no profile file. */
class UnauthorizedProfileTest extends TestCase
{
    use PostgresSafetyFixtures;

    public function test_independent_laravel_boot_cannot_authorize_writes(): void
    {
        $environment = ['HORUS_PG_TEST_CONFIRM' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null'] + $this->safeEnvironment();
        $this->withEnvironment($environment, function (): void {
            $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
            // Never read the local PostgreSQL profile or the daily environment.
            $app->loadEnvironmentFrom('nonexistent-preflight-boundary-fixture');
            try {
                $app->make(Kernel::class)->bootstrap();
                $this->fail('Missing consent was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('destructive-test consent required', $error->getMessage());
                $this->assertFalse(TestDatabaseSafety::readOnlyProbe($app));
            } finally {
                $app->flush();
            }
        });
    }
}
