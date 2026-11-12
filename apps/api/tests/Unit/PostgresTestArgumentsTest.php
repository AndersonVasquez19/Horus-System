<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Testing\PostgresTestArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Lista cerrada de opciones de `php scripts/pgsql-test.php test`. Sin base de datos.
 */
class PostgresTestArgumentsTest extends TestCase
{
    public function test_stop_on_error_stops_on_the_first_error_or_failure(): void
    {
        $this->assertSame(
            ['--filter=Etapa', '--stop-on-error', '--stop-on-failure'],
            PostgresTestArguments::phpunitOptions(['--filter=Etapa', '--stop-on-error']),
        );
    }

    public function test_existing_options_keep_their_meaning_and_duplicates_are_collapsed(): void
    {
        $this->assertSame([], PostgresTestArguments::phpunitOptions([]));
        $this->assertSame(['--stop-on-failure'], PostgresTestArguments::phpunitOptions(['--stop-on-failure']));
        $this->assertSame(
            ['--stop-on-failure', '--stop-on-error', '--filter=A|B'],
            PostgresTestArguments::phpunitOptions(['--stop-on-failure', '--stop-on-error', '--filter=A|B', '--stop-on-error']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedArguments(): iterable
    {
        // Opciones que ocultan avisos, cambian la configuración o detendrían la etapa ante «risky».
        yield 'defect' => ['--stop-on-defect'];
        yield 'risky' => ['--stop-on-risky'];
        yield 'no-risky' => ['--do-not-fail-on-risky'];
        yield 'configuration' => ['--configuration=phpunit.xml'];
        yield 'bootstrap' => ['--bootstrap=other.php'];
        yield 'empty filter' => ['--filter='];
        yield 'separate filter value' => ['--filter'];
        yield 'similar spelling' => ['--stop-on-errors'];
    }

    #[DataProvider('rejectedArguments')]
    public function test_any_other_option_is_rejected(string $argument): void
    {
        $this->expectException(RuntimeException::class);
        PostgresTestArguments::phpunitOptions(['--stop-on-error', $argument]);
    }
}
