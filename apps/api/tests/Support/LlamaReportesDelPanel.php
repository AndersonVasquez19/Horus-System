<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama a las exportaciones del reporte de matrículas por la API del panel (/api/panel/v1/academico/reportes/exportar/*),
 * como lo hace el BFF de apps/web. Sustituye a las rutas Blade `admin.reportes.exportar.*`, retiradas el 14/11/2026.
 * El usuario se fija antes con `actingAs()`.
 */
trait LlamaReportesDelPanel
{
    /** @param array<string, mixed> $datos */
    protected function exportarReporte(string $formato, array $datos = []): TestResponse
    {
        config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);

        return $this->withHeaders(['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789'])
            ->postJson('/api/panel/v1/academico/reportes/exportar/'.$formato, $datos);
    }
}
