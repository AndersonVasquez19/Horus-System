<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\EscenarioReversible;

/**
 * Caminos de error del arnés transaccional de la validación PostgreSQL de la migración 000032, con cierres
 * simulados (sin base de datos). No prueba PostgreSQL: prueba que el arnés conserva los errores y no declara una
 * limpieza que no pudo comprobar.
 */
class EscenarioReversibleTest extends TestCase
{
    /** @var list<string> */
    private array $pasos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasos = [];
    }

    /**
     * @param  array<string, callable>  $cambios
     */
    private function ejecutar(array $cambios = []): void
    {
        $paso = fn (string $nombre, mixed $devuelve = null): callable => function () use ($nombre, $devuelve) {
            $this->pasos[] = $nombre;

            return $devuelve;
        };
        $base = [
            'escenario' => $paso('escenario'),
            'sinResiduos' => $paso('sinResiduos'),
            'revertir' => $paso('revertir'),
            'nivel' => $paso('nivel', 0),
            'estado' => $paso('estado'),
        ];
        $c = $cambios + $base;
        EscenarioReversible::ejecutar($c['escenario'], $c['sinResiduos'], $c['revertir'], $c['nivel'], $c['estado']);
    }

    public function test_a_clean_scenario_checks_residues_then_rolls_back_then_checks_the_persistent_state(): void
    {
        $this->ejecutar();

        $this->assertSame(['escenario', 'sinResiduos', 'revertir', 'nivel', 'estado'], $this->pasos);
    }

    public function test_the_original_error_is_rethrown_as_the_same_object_when_cleanup_is_clean(): void
    {
        $original = new LogicException('fallo del escenario');

        try {
            $this->ejecutar(['escenario' => function () use ($original): void {
                $this->pasos[] = 'escenario';
                throw $original;
            }]);
            $this->fail('Debió relanzarse el error original');
        } catch (\Throwable $e) {
            $this->assertSame($original, $e);
        }

        $this->assertSame(['escenario', 'revertir', 'nivel', 'estado'], $this->pasos, 'Sin comprobación de residuos tras un error; rollback y estado sí');
    }

    public function test_a_rollback_failure_after_an_original_error_keeps_both_and_reports_the_state_as_unchecked(): void
    {
        $original = new LogicException('fallo del escenario');
        $rollback = new RuntimeException('rollback roto');

        try {
            $this->ejecutar([
                'escenario' => fn () => throw $original,
                'revertir' => function () use ($rollback): void {
                    $this->pasos[] = 'revertir';
                    throw $rollback;
                },
            ]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertSame($original, $e->getPrevious(), 'El error original queda encadenado');
            $this->assertStringContainsString('Error original: '.LogicException::class.': fallo del escenario', $e->getMessage());
            $this->assertStringContainsString('Fallo del rollback: '.RuntimeException::class.': rollback roto', $e->getMessage());
            $this->assertStringContainsString('Estado persistente NO comprobado', $e->getMessage());
            $this->assertStringContainsString('no se declara limpieza satisfactoria', $e->getMessage());
        }

        $this->assertNotContains('nivel', $this->pasos);
        $this->assertNotContains('estado', $this->pasos, 'No se comprueba el estado con una conexión dudosa');
    }

    public function test_a_rollback_failure_after_a_successful_scenario_is_not_reported_as_success(): void
    {
        try {
            $this->ejecutar(['revertir' => fn () => throw new RuntimeException('rollback roto')]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertStringNotContainsString('Error original', $e->getMessage());
            $this->assertStringContainsString('Fallo del rollback', $e->getMessage());
            $this->assertStringContainsString('NO comprobado', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function test_residues_before_the_rollback_are_reported_but_the_rollback_and_the_state_check_still_run(): void
    {
        try {
            $this->ejecutar(['sinResiduos' => fn () => throw new AssertionFailedError('quedó una restricción de referencia')]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Residuos antes del rollback', $e->getMessage());
            $this->assertStringContainsString('quedó una restricción de referencia', $e->getMessage());
        }

        $this->assertSame(['escenario', 'revertir', 'nivel', 'estado'], $this->pasos);
    }

    public function test_a_wrong_persistent_state_after_the_rollback_is_reported_together_with_the_original_error(): void
    {
        $original = new LogicException('fallo del escenario');

        try {
            $this->ejecutar([
                'escenario' => fn () => throw $original,
                'estado' => fn () => throw new AssertionFailedError('quedó una función auxiliar'),
            ]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertStringContainsString('Error original: '.LogicException::class, $e->getMessage());
            $this->assertStringContainsString('Estado persistente incorrecto tras el rollback', $e->getMessage());
            $this->assertStringContainsString('quedó una función auxiliar', $e->getMessage());
        }
    }

    public function test_a_connection_still_in_a_transaction_after_the_rollback_is_never_declared_clean(): void
    {
        try {
            $this->ejecutar(['nivel' => fn () => 1]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('sigue en el nivel de transacción 1', $e->getMessage());
            $this->assertStringContainsString('Estado persistente NO comprobado', $e->getMessage());
        }

        $this->assertNotContains('estado', $this->pasos);
    }

    public function test_an_unreadable_transaction_level_is_reported_and_the_state_is_not_checked(): void
    {
        try {
            $this->ejecutar(['nivel' => fn () => throw new RuntimeException('conexión perdida')]);
            $this->fail('Debió fallar');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('No se pudo leer el nivel de transacción: '.RuntimeException::class.': conexión perdida', $e->getMessage());
            $this->assertStringContainsString('Estado persistente NO comprobado', $e->getMessage());
        }

        $this->assertNotContains('estado', $this->pasos);
    }
}
