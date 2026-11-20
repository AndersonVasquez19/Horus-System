<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad que no alteran lo que ve el visitante.
 *
 * La Content-Security-Policy depende de `csp.mode` (ver config/csp.php): `report-only` (por defecto) solo informa
 * de lo que bloquearía; `enforce` bloquea; `off` o un valor desconocido no envían nada. Una cabecera ya presente
 * en la respuesta (p. ej. una ruta con una política más estricta) no se sobrescribe.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }

        if ($this->isHtml($response)) {
            // Solo los valores exactos `enforce` y `report-only` envían una cabecera: un valor desconocido o
            // mal escrito nunca bloquea por accidente.
            $cabecera = match (config('csp.mode')) {
                'enforce' => 'Content-Security-Policy',
                'report-only' => 'Content-Security-Policy-Report-Only',
                default => null,
            };

            if ($cabecera !== null) {
                $defaults[$cabecera] = $this->policy();
            }
        }

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * La CSP solo aplica a documentos; las respuestas JSON, descargas y redirecciones no la necesitan. Una
     * redirección lleva un cuerpo HTML mínimo (`text/html`), por eso se descarta de forma explícita.
     */
    private function isHtml(Response $response): bool
    {
        return ! $response->isRedirection()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function policy(): string
    {
        /** @var array<string, list<string>> $directives */
        $directives = (array) config('csp.directives', []);

        return implode('; ', array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            array_values($directives),
        ));
    }
}
