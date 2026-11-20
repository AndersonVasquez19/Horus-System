<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Lee el catálogo público de cursos por su única ruta vigente (`GET /api/public/v1/cursos`, la del BFF de apps/web).
 * La página Blade `/educacion/cursos` se retiró en la Fase 1 del retiro de Blade; la regla de qué ficha se muestra es la misma
 * (`CatalogoPublicoCursos`).
 */
trait CursosPublicosBff
{
    protected function cursosWeb(string $consulta = ''): TestResponse
    {
        $token = 'token-de-prueba-bff-0123456789abcdefghij';
        config(['horus-web.service_token' => $token]);

        return $this->getJson('/api/public/v1/cursos'.$consulta, ['X-Horus-Web-Token' => $token]);
    }

    /** Texto completo de la respuesta (JSON sin escapar tildes), para comprobar qué se muestra y qué no. */
    protected function textoCursosWeb(string $consulta = ''): string
    {
        return (string) json_encode($this->cursosWeb($consulta)->assertOk()->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
