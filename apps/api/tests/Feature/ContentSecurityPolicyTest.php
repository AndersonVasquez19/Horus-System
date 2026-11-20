<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** CSP en modo solo reporte: informa, nunca bloquea ni sustituye otras cabeceras. */
class ContentSecurityPolicyTest extends TestCase
{
    private const REPORT = 'Content-Security-Policy-Report-Only';

    /** Un HTML cualquiera de Laravel (hoy solo las páginas de error): la política que recibe. */
    private function policy(string $cabecera): string
    {
        $respuesta = $this->get('/ruta-que-no-existe');
        $respuesta->assertNotFound();

        return (string) $respuesta->headers->get($cabecera);
    }

    public function test_html_pages_send_the_policy_in_report_only_mode_without_unsafe_eval_or_vite(): void
    {
        config(['csp.mode' => 'report-only']);

        $politica = $this->policy(self::REPORT);

        $this->assertStringContainsString("default-src 'self'", $politica);
        $this->assertStringContainsString("script-src 'self'", $politica);
        $this->assertStringNotContainsString('unsafe-eval', $politica);
        $this->assertStringNotContainsString('5173', $politica, 'Ya no hay servidor de desarrollo de Vite.');
        $this->assertFalse($this->get('/ruta-que-no-existe')->headers->has('Content-Security-Policy'));
    }

    public function test_enforce_mode_sends_a_blocking_policy_and_no_report_only_header(): void
    {
        config(['csp.mode' => 'enforce']);

        $respuesta = $this->get('/ruta-que-no-existe');

        $this->assertNotSame('', (string) $respuesta->headers->get('Content-Security-Policy'));
        $this->assertFalse($respuesta->headers->has(self::REPORT));
    }

    public function test_the_policy_is_not_sent_when_disabled_or_the_mode_is_unknown(): void
    {
        foreach (['off', 'otro-valor'] as $modo) {
            config(['csp.mode' => $modo]);
            $respuesta = $this->get('/ruta-que-no-existe');

            $this->assertFalse($respuesta->headers->has('Content-Security-Policy'), $modo);
            $this->assertFalse($respuesta->headers->has(self::REPORT), $modo);
        }
    }

    public function test_enforce_mode_does_not_touch_json_or_redirect_responses(): void
    {
        config(['csp.mode' => 'enforce']);

        $this->assertFalse($this->getJson(route('api.auth.me'))->headers->has('Content-Security-Policy'));
        \Illuminate\Support\Facades\Route::middleware('web')->get('/__redireccion', fn () => redirect('/'));
        $this->assertFalse($this->get('/__redireccion')->headers->has('Content-Security-Policy'));
    }

    public function test_the_default_mode_is_report_only(): void
    {
        // Se lee el valor por defecto declarado en el archivo, no el `.env` local (que puede tener otro modo).
        $this->assertStringContainsString("env('CSP_MODE', 'report-only')", (string) file_get_contents(config_path('csp.php')));
    }

    public function test_an_existing_stricter_header_is_not_overwritten(): void
    {
        config(['csp.mode' => 'enforce']);
        \Illuminate\Support\Facades\Route::get('/__csp-previa', fn () => response('<p>x</p>', 200, [
            'Content-Type' => 'text/html',
            'Content-Security-Policy' => "default-src 'none'",
        ]));

        $this->get('/__csp-previa')->assertHeader('Content-Security-Policy', "default-src 'none'");
    }

    public function test_json_responses_do_not_carry_the_policy(): void
    {
        config(['csp.mode' => 'report-only']);

        $response = $this->getJson(route('api.auth.me'));

        $this->assertFalse($response->headers->has(self::REPORT));
    }

}
