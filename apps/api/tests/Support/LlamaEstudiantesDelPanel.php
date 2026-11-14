<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama al módulo de estudiantes por la API del panel (/api/panel/v1/academico/estudiantes), como lo hace el BFF de
 * apps/web. Sustituye a las rutas Blade `admin.estudiantes.*`, retiradas el 14/11/2026. El usuario se fija antes con
 * `actingAs()`. Las cabeceras viajan solo en cada petición (withHeaders() las dejaría pegadas a la prueba).
 *
 * Diferencias con el módulo Blade: respuesta JSON `{message?, data}`, errores de validación 422 con `errors`, la
 * restauración es POST `…/{id}/restaurar`, desactivar con matrículas vigentes responde 409 y el listado usa
 * `page`/`per_page` y devuelve `data` + `meta`.
 */
trait LlamaEstudiantesDelPanel
{
    private function tokenPanelEstudiantes(): string
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $datos  cuerpo (o parámetros de consulta si el método es GET)
     * @param  array<string, string>  $cabeceras
     */
    protected function panelEstudiantes(string $metodo, string $ruta = '', array $datos = [], array $cabeceras = []): TestResponse
    {
        $url = '/api/panel/v1/academico/estudiantes'.($ruta === '' ? '' : '/'.ltrim($ruta, '/'));
        if ($metodo === 'GET' && $datos !== []) {
            $url .= '?'.http_build_query($datos);
            $datos = [];
        }

        return $this->json($metodo, $url, $datos, ['X-Horus-Web-Token' => $this->tokenPanelEstudiantes(), ...$cabeceras]);
    }
}
