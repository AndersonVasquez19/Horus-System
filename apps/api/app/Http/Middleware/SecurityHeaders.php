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

        if ($vite = $this->viteDevOrigin()) {
            $esquema = str_starts_with($vite, 'https:') ? 'https' : 'http';
            $esquemaWs = $esquema === 'https' ? 'wss' : 'ws';

            // Una dirección IPv6 entre corchetes (`http://[::1]:5173`, la que usa Vite por defecto) NO es una
            // fuente válida de CSP: el navegador la descarta y bloquea los recursos de Vite. Solo para ese caso, y
            // solo fuera de producción, se permite el esquema completo; con un nombre de host se limita al origen.
            $ipv6 = str_contains($vite, '[');
            $origen = $ipv6 ? $esquema.':' : $vite;
            $socket = $ipv6 ? $esquemaWs.':' : $esquemaWs.substr($vite, strlen($esquema));

            // Servidor de desarrollo de Vite: scripts, estilos, fuentes, imágenes y WebSocket de recarga.
            foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $directive) {
                $directives[$directive][] = $origen;
            }
            $directives['connect-src'][] = $origen;
            $directives['connect-src'][] = $socket;
        }

        return implode('; ', array_map(
            fn (string $name, array $sources): string => $name.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            array_values($directives),
        ));
    }

    /** Origen de Vite solo fuera de producción y solo si el archivo `hot` contiene una URL http(s) válida. */
    private function viteDevOrigin(): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        $file = (string) config('csp.vite_hot_file');

        if ($file === '' || ! is_file($file)) {
            return null;
        }

        $parts = parse_url(trim((string) file_get_contents($file)));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
