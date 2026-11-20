<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Llama a los usuarios por la API del panel (`/api/panel/v1/compartido/usuarios`), como lo hace el BFF de apps/web.
 * Sustituye a las rutas Blade `admin.usuarios.*`, retiradas el 14/11/2026. El usuario se fija antes con `actingAs()`.
 *
 * Equivalencias con el Blade: la redirección con éxito pasa a 200/201 JSON; los errores de validación y de
 * `AdminProtection` (antes en la sesión) pasan a 422 con `errors`; las denegaciones R1 a R3 siguen siendo 403.
 * Los métodos devuelven la respuesta, de modo que se pueden encadenar tras `actingAs()`.
 */
trait LlamaUsuariosDelPanel
{
    /**
     * @param  array<string, mixed>  $datos  cuerpo (POST/PUT) o consulta (GET)
     */
    protected function llamarUsuariosPanel(string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        $url = '/api/panel/v1/compartido/usuarios'.($ruta === '' ? '' : '/'.$ruta);
        if ($metodo === 'GET' && $datos !== []) {
            $url .= '?'.http_build_query($datos);
            $datos = [];
        }

        return $this->json($metodo, $url, $datos, ['X-Horus-Web-Token' => $token]);
    }

    /** @param  array<string, mixed>  $datos */
    protected function crearUsuarioPanel(array $datos): TestResponse
    {
        return $this->llamarUsuariosPanel('POST', '', $datos);
    }

    /** @param  array<string, mixed>  $datos */
    protected function actualizarUsuarioPanel(User|int|string $usuario, array $datos): TestResponse
    {
        return $this->llamarUsuariosPanel('PUT', (string) ($usuario instanceof User ? $usuario->getKey() : $usuario), $datos);
    }

    protected function eliminarUsuarioPanel(User|int|string $usuario): TestResponse
    {
        return $this->llamarUsuariosPanel('DELETE', (string) ($usuario instanceof User ? $usuario->getKey() : $usuario));
    }
}
