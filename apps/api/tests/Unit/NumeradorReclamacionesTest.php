<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NumeradorReclamaciones;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Precondiciones del numerador sin base de datos (portable: no abre ni revierte ninguna transacción real).
 * El comportamiento con tablas está en GestionReclamacionesTest; la concurrencia real, en
 * tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.
 */
class NumeradorReclamacionesTest extends TestCase
{
    private function conexion(int $nivel): ConnectionInterface
    {
        $conexion = $this->createMock(ConnectionInterface::class);
        $conexion->method('transactionLevel')->willReturn($nivel);
        // Ninguna consulta debe ejecutarse si falla una precondición.
        $conexion->expects($this->never())->method('table');

        return $conexion;
    }

    public function test_exige_una_transaccion_abierta_antes_de_consultar(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transacción abierta');
        (new NumeradorReclamaciones)->siguiente(2026, $this->conexion(0));
    }

    public function test_rechaza_anios_fuera_de_rango_antes_de_consultar(): void
    {
        foreach ([1999, 10000, 0, -1] as $anio) {
            try {
                (new NumeradorReclamaciones)->siguiente($anio, $this->conexion(1));
                $this->fail("Debió rechazar el año $anio");
            } catch (LogicException $e) {
                $this->assertStringContainsString('fuera de rango', $e->getMessage());
            }
        }
    }
}
