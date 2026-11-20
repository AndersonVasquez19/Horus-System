<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama a la exportación administrativa por la API del panel (`/api/panel/v1/compartido/respaldo`), como lo hace el BFF de
 * apps/web. Sustituye a las rutas Blade `admin.respaldo.*`, retiradas en la Fase 2 del retiro de Blade. El usuario se fija
 * antes con `actingAs()`.
 */
trait LlamaRespaldoDelPanel
{
    protected function panelRespaldo(string $metodo, string $ruta = ''): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $this->json($metodo, '/api/panel/v1/compartido/respaldo'.($ruta === '' ? '' : '/'.ltrim($ruta, '/')), [], [
            'X-Horus-Web-Token' => $token,
        ]);
    }
}
