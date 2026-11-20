<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama a las matrículas por la API del panel (/api/panel/v1/academico/matriculas), como lo hace el BFF de apps/web.
 * Sustituye a las rutas Blade `admin.matriculas.*`, retiradas el 14/11/2026. El usuario se fija antes con `actingAs()`.
 * Las cabeceras viajan solo en cada petición (withHeaders() las dejaría pegadas a la prueba y contaminaría después las
 * aserciones de la API pública).
 *
 * Diferencias con el módulo Blade: respuesta JSON `{message?, data}`, validación 422 con `errors`, anular y restaurar
 * son POST, la exportación CSV es `POST …/exportar` y las opciones de los selectores llegan en `data` (no en `opciones`).
 */
trait LlamaMatriculasDelPanel
{
    private function tokenPanelMatriculas(): string
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
    protected function panelMatriculas(string $metodo, string $ruta = '', array $datos = [], array $cabeceras = []): TestResponse
    {
        $url = '/api/panel/v1/academico/matriculas'.($ruta === '' ? '' : '/'.ltrim($ruta, '/'));
        if ($metodo === 'GET' && $datos !== []) {
            $url .= '?'.http_build_query($datos);
            $datos = [];
        }

        return $this->json($metodo, $url, $datos, ['X-Horus-Web-Token' => $this->tokenPanelMatriculas(), ...$cabeceras]);
    }
}
