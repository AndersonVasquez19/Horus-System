<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama a las aprobaciones por la API del panel (`/api/panel/v1/compartido/aprobaciones`), como lo hace el BFF de apps/web.
 * Sustituye a las rutas Blade `admin.aprobaciones.*`, retiradas el 15/11/2026. El usuario se fija antes con `actingAs()`.
 *
 * Equivalencias con el Blade: la redirección con éxito pasa a 200/201 JSON y los errores de validación (antes en la
 * sesión) pasan a 422 con `errors`; las denegaciones (403) y los conflictos de estado (409) son los mismos.
 */
trait LlamaAprobacionesDelPanel
{
    /** @param  array<string, mixed>  $datos  cuerpo (POST/PUT) o consulta (GET) */
    protected function panelAprobaciones(string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        $url = '/api/panel/v1/compartido/aprobaciones'.($ruta === '' ? '' : '/'.$ruta);
        if ($metodo === 'GET' && $datos !== []) {
            $url .= '?'.http_build_query($datos);
            $datos = [];
        }

        return $this->json($metodo, $url, $datos, ['X-Horus-Web-Token' => $token]);
    }
}
