<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use PHPUnit\Framework\TestCase;

/**
 * D9 está en cuarentena. No arranca Laravel, no conecta y no contiene comandos destructivos.
 * Antes de incorporar un ejecutor se exige autorización exclusiva de reconstrucción, identidad
 * física verificada y certificación del destino desechable. El consentimiento PG general no basta.
 * Contrato y comprobaciones que deben recuperarse: docs/consolidacion-fase-4-postgresql.md.
 */
final class ReclamacionesReconstruccionD9Test extends TestCase
{
    public function test_d9_reconstruccion_requiere_un_ejecutor_desechable_autorizado(): void
    {
        $this->markTestSkipped('D9 bloqueada: falta autorización específica y ejecutor con identidad física verificada.');
    }
}
