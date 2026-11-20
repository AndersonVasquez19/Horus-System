<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PostgresBootstrapLifecycleTest extends TestCase
{
    private function fixture(string $file, array $options = [], array $environment = []): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml',
            '--bootstrap', $root.'/tests/Fixtures/Handlers/bootstrap-preflight-double.php',
            '--do-not-cache-result', '--fail-on-risky', '--fail-on-warning',
            ...$options, $root.'/tests/Fixtures/Handlers/'.$file], $root, $environment + [
                'HORUS_PG_TEST_PROFILE' => false, 'HORUS_PG_TEST_CONFIRM' => false,
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
            ]);
        $process->run();

        return $process;
    }

    public function test_real_laravel_lifecycle_preserves_phpunit_handlers_across_two_applications(): void
    {
        $process = $this->fixture('HandlerLifecycleFixtureTest.php');
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (3 tests,', $process->getOutput());
        $this->assertStringNotContainsString('Risky:', $process->getOutput());
    }

    public function test_direct_pg_bootstrap_refusal_blocks_the_first_test(): void
    {
        $process = $this->fixture('HandlerLifecycleFixtureTest.php', [], ['HORUS_HANDLER_FIXTURE_REJECT' => '1']);
        $this->assertNotSame(0, $process->getExitCode());
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('Fixture preflight refused before any test.', $output);
        $this->assertStringNotContainsString('test_first_laravel_application', $output);
        $this->assertStringNotContainsString('Tests: 2', $output);
    }

    public static function signals(): iterable
    {
        yield 'warning' => ['test_warning_is_reported', 'Warnings: 1'];
        yield 'exception' => ['test_exception_is_reported', 'Errors: 1'];
        yield 'removed error handler' => ['test_removed_handler_is_reported', 'removed error handlers other than its own'];
        yield 'leaked exception handler' => ['test_leaked_handler_is_reported', 'did not remove its own exception handlers'];
    }

    public function test_warning_after_laravel_teardown_still_reaches_phpunit(): void
    {
        $process = $this->fixture('HandlerLifecycleFixtureTest.php', ['--filter=test_first_laravel_application'],
            ['HORUS_HANDLER_FIXTURE_WARNING_AFTER' => '1']);
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Warnings: 1', $process->getOutput());
        $this->assertStringNotContainsString('Risky:', $process->getOutput());
    }

    #[DataProvider('signals')]
    public function test_phpunit_still_detects_real_defects(string $method, string $expected): void
    {
        $process = $this->fixture('HandlerSignalsFixtureTest.php', ['--filter='.$method]);
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString($expected, $process->getOutput().$process->getErrorOutput());
    }
}
