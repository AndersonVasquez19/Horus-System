<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Curso;
use Illuminate\Testing\TestResponse;

/**
 * Llama a los cursos y a su ficha pública por la API del panel (/api/panel/v1/academico/cursos), como lo hace el BFF de
 * apps/web. Sustituye a las rutas Blade `admin.cursos.*` y `admin.cursos.publicacion.*`, retiradas el 14/11/2026. El
 * usuario se fija antes con `actingAs()`. Las cabeceras viajan solo en cada petición (withHeaders() las dejaría
 * pegadas a la prueba).
 *
 * Diferencias con el módulo Blade: respuesta JSON `{message?, data}`, validación 422 con `errors`, restaurar y retirar
 * la ficha son POST, archivar con matrículas vigentes responde 409 y, sobre todo, **la revisión de la ficha es
 * obligatoria** (Blade aceptaba clientes sin precondición). Por eso `publicarFichaCursoPanel()` y
 * `retirarFichaCursoPanel()` obtienen la revisión vigente cuando no se les da una: así se prepara un escenario como en
 * Blade, y cuando una prueba quiere probar la precondición pasa la revisión (o usa `panelCursos()` sin ella).
 */
trait LlamaCursosDelPanel
{
    private function tokenPanelCursos(): string
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
    protected function panelCursos(string $metodo, string $ruta = '', array $datos = [], array $cabeceras = []): TestResponse
    {
        $url = '/api/panel/v1/academico/cursos'.($ruta === '' ? '' : '/'.ltrim($ruta, '/'));
        if ($metodo === 'GET' && $datos !== []) {
            $url .= '?'.http_build_query($datos);
            $datos = [];
        }

        return $this->json($metodo, $url, $datos, ['X-Horus-Web-Token' => $this->tokenPanelCursos(), ...$cabeceras]);
    }

    private function idCursoPanel(Curso|int|string $curso): string
    {
        return (string) ($curso instanceof Curso ? $curso->id : $curso);
    }

    /** Editor de la ficha: `data.revision`, `data.ficha`, `data.publicable` y la cabecera `ETag`. */
    protected function fichaCursoPanel(Curso|int|string $curso): TestResponse
    {
        return $this->panelCursos('GET', $this->idCursoPanel($curso).'/publicacion');
    }

    /**
     * Revisión vigente de la ficha. Si el usuario no puede abrir el editor (403/401) devuelve una revisión con formato
     * válido pero inexistente, para que la petición de escritura llegue hasta su propio rechazo.
     */
    protected function revisionFichaCursoPanel(Curso|int|string $curso): string
    {
        $revision = $this->fichaCursoPanel($curso)->headers->get('ETag');

        return is_string($revision) && $revision !== '' ? $revision : '"'.str_repeat('0', 64).'"';
    }

    /** @param array<string, mixed> $datos */
    protected function publicarFichaCursoPanel(Curso|int|string $curso, array $datos, ?string $revision = null): TestResponse
    {
        return $this->panelCursos('PUT', $this->idCursoPanel($curso).'/publicacion', $datos, ['If-Match' => $revision ?? $this->revisionFichaCursoPanel($curso)]);
    }

    protected function retirarFichaCursoPanel(Curso|int|string $curso, ?string $revision = null): TestResponse
    {
        return $this->panelCursos('POST', $this->idCursoPanel($curso).'/publicacion/retirar', [], ['If-Match' => $revision ?? $this->revisionFichaCursoPanel($curso)]);
    }
}
