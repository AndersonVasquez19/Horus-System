<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Llama a los roles por la API del panel (`/api/panel/v1/compartido/roles`), como lo hace el BFF de apps/web.
 * Sustituye a las rutas Blade `admin.roles.*`, retiradas el 14/11/2026. El usuario se fija antes con `actingAs()`.
 *
 * Equivalencias con el Blade: la redirección con éxito pasa a 200/201 JSON; los errores de validación y de
 * `AdminProtection` (antes en la sesión) pasan a 422 con `errors`; las denegaciones R4 siguen siendo 403.
 * Los métodos devuelven la respuesta, de modo que se pueden encadenar tras `actingAs()`.
 */
trait LlamaRolesDelPanel
{
    /** @param  array<string, mixed>  $datos */
    protected function llamarRolesPanel(string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $this->json($metodo, '/api/panel/v1/compartido/roles'.($ruta === '' ? '' : '/'.$ruta), $datos, ['X-Horus-Web-Token' => $token]);
    }

    /** @param  array<string, mixed>  $datos */
    protected function crearRolPanel(array $datos): TestResponse
    {
        return $this->llamarRolesPanel('POST', '', $datos);
    }

    /** @param  array<string, mixed>  $datos */
    protected function actualizarRolPanel(Role $rol, array $datos): TestResponse
    {
        return $this->llamarRolesPanel('PUT', (string) $rol->getKey(), $datos);
    }

    protected function eliminarRolPanel(Role $rol): TestResponse
    {
        return $this->llamarRolesPanel('DELETE', (string) $rol->getKey());
    }
}
