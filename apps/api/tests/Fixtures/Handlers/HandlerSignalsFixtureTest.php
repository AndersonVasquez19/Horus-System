<?php

declare(strict_types=1);

namespace Tests\Fixtures\Handlers;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Intentionally defective controls, only invoked in explicitly selected subprocesses. */
class HandlerSignalsFixtureTest extends TestCase
{
    public function test_warning_is_reported(): void
    {
        trigger_error('handler-fixture-warning', E_USER_WARNING);
        $this->assertTrue(true);
    }

    public function test_exception_is_reported(): void
    {
        throw new RuntimeException('handler-fixture-exception');
    }

    public function test_removed_handler_is_reported(): void
    {
        restore_error_handler();
        $this->assertTrue(true);
    }

    public function test_leaked_handler_is_reported(): void
    {
        set_exception_handler(static fn () => null);
        $this->assertTrue(true);
    }
}
