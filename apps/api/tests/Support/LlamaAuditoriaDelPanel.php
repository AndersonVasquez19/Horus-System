<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama a la auditoría por la API del panel (/api/panel/v1/compartido/auditoria), como lo hace el BFF de apps/web.
 * Sustituye a las rutas Blade `admin.auditoria.*`, retiradas el 14/11/2026. El usuario se fija antes con `actingAs()`.
 *
 * Diferencias con el módulo Blade: el listado responde JSON `{data, meta}` (con `accion`, `modelo`, `cambios` ya
 * enmascarados) y la exportación es `POST …/exportar` con los filtros en la consulta, como la reenvía el BFF.
 */
trait LlamaAuditoriaDelPanel
{
    /**
     * @param  array<string, mixed>  $consulta  filtros (`accion`, `modelo`, `usuario`, `desde`, `hasta`, `page`…)
     */
    protected function panelAuditoria(string $metodo, string $ruta = '', array $consulta = []): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        $url = '/api/panel/v1/compartido/auditoria'.($ruta === '' ? '' : '/'.ltrim($ruta, '/'));
        if ($consulta !== []) {
            $url .= '?'.http_build_query($consulta);
        }

        return $this->json($metodo, $url, [], ['X-Horus-Web-Token' => $token]);
    }
}
