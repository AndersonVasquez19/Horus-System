<?php

declare(strict_types=1);

namespace Tests\Fixtures\Handlers;

use ErrorException;
use Illuminate\Foundation\Application;
use PHPUnit\Runner\ErrorHandler;
use Tests\TestCase;

class HandlerLifecycleFixtureTest extends TestCase
{
    private mixed $previousErrorHandler;

    private mixed $previousExceptionHandler;

    private static ?Application $firstApplication = null;

    protected function setUp(): void
    {
        $this->previousErrorHandler = get_error_handler();
        $this->previousExceptionHandler = get_exception_handler();
        $this->assertTrue(PreflightDouble::$verified);
        $this->assertInstanceOf(ErrorHandler::class, $this->previousErrorHandler);
        parent::setUp();
        $this->assertTrue($this->app->hasBeenBootstrapped());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertNotSame($this->previousErrorHandler, get_error_handler());
    }

    public function test_first_laravel_application(): void
    {
        self::$firstApplication = $this->app;
        $this->assertNotNull(get_error_handler());
    }

    public function test_second_laravel_application_is_independent(): void
    {
        $this->assertNotSame(self::$firstApplication, $this->app);
    }

    public function test_laravel_warning_reaches_phpunit_as_error_exception(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('laravel-handler-warning');
        trigger_error('laravel-handler-warning', E_USER_WARNING);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->assertSame($this->previousErrorHandler, get_error_handler());
        $this->assertSame($this->previousExceptionHandler, get_exception_handler());
        if (getenv('HORUS_HANDLER_FIXTURE_WARNING_AFTER') === '1') {
            trigger_error('warning-after-laravel-teardown', E_USER_WARNING);
        }
    }
}
