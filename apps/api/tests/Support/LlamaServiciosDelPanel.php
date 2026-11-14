<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Llama al catálogo de servicios tecnológicos por la API del panel (/api/panel/v1/servicios/servicios/*), como lo hace
 * el BFF de apps/web. Sustituye a las rutas Blade `admin.servicios.*`, retiradas el 14/11/2026. El usuario se fija
 * antes con `actingAs()`. La revisión viaja en la cabecera `If-Match` (el panel la acepta además de `_revision`).
 *
 * Diferencias con el módulo Blade: la respuesta es `{message?, data}` (`data.id`, `data.estado_editorial`,
 * `data.ficha.*`), «retirar» y «estado» son POST y la imagen y las tarjetas se piden por `…/imagen` y `…/elementos`.
 */
trait LlamaServiciosDelPanel
{
    private function tokenPanelServicios(): string
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    protected function panelServicios(string $metodo, string $ruta = '', array $datos = [], array $cabeceras = []): TestResponse
    {
        // Las cabeceras viajan solo en esta petición (withHeaders() las dejaría pegadas a la prueba y contaminaría
        // las llamadas públicas posteriores, que deben seguir exigiendo su propio token).
        return $this->json($metodo, $this->rutaPanelServicios($ruta), $datos, ['X-Horus-Web-Token' => $this->tokenPanelServicios(), ...$cabeceras]);
    }

    /**
     * Multipart (imagen principal y tarjetas con fotografía).
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, string>  $cabeceras
     */
    protected function panelServiciosArchivo(string $ruta, array $datos, array $cabeceras = []): TestResponse
    {
        return $this->post($this->rutaPanelServicios($ruta), $datos, ['X-Horus-Web-Token' => $this->tokenPanelServicios(), 'Accept' => 'application/json', ...$cabeceras]);
    }

    private function rutaPanelServicios(string $ruta): string
    {
        $base = '/api/panel/v1/servicios/servicios';

        return match (true) {
            $ruta === '' => $base,
            str_starts_with($ruta, '?') => $base.$ruta,
            default => $base.'/'.ltrim($ruta, '/'),
        };
    }

    /** @param array<string, mixed> $datos */
    protected function crearServicioPanel(array $datos): int
    {
        return (int) $this->panelServicios('POST', '', $datos)->assertCreated()->json('data.id');
    }

    /** Revisión vigente (`ETag`) del servicio. */
    protected function revisionServicioPanel(int $id): string
    {
        return (string) $this->panelServicios('GET', (string) $id)->assertOk()->headers->get('ETag');
    }

    /** @param array<string, mixed> $ficha */
    protected function guardarFichaPanel(int $id, array $ficha): TestResponse
    {
        return $this->panelServicios('PUT', "$id/publicacion", $ficha, ['If-Match' => $this->revisionServicioPanel($id)]);
    }

    protected function cambiarEstadoServicioPanel(int $id, string $estado): TestResponse
    {
        return $this->panelServicios('POST', "$id/estado", ['estado' => $estado], ['If-Match' => $this->revisionServicioPanel($id)]);
    }

    protected function autorizarFichaPanel(int $id): TestResponse
    {
        return $this->panelServicios('POST', "$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $this->revisionServicioPanel($id)]);
    }

    protected function retirarFichaPanel(int $id): TestResponse
    {
        return $this->panelServicios('POST', "$id/publicacion/retirar", [], ['If-Match' => $this->revisionServicioPanel($id)]);
    }
}
