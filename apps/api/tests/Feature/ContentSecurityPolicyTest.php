<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** CSP en modo solo reporte: informa, nunca bloquea ni sustituye otras cabeceras. */
class ContentSecurityPolicyTest extends TestCase
{
    private const REPORT = 'Content-Security-Policy-Report-Only';

    protected function setUp(): void
    {
        parent::setUp();
        // Sin servidor Vite real durante las pruebas, salvo que cada prueba lo pida.
        config(['csp.vite_hot_file' => sys_get_temp_dir().'/horus-no-hot-'.uniqid()]);
    }

    public function test_html_pages_send_the_policy_in_report_only_mode(): void
    {
        config(['csp.mode' => 'report-only']);

        $response = $this->get(route('login'));

        $response->assertOk()->assertHeader(self::REPORT);
        $this->assertFalse($response->headers->has('Content-Security-Policy'), 'No debe haber una cabecera bloqueante.');

        $policy = (string) $response->headers->get(self::REPORT);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'", 'frame-src https://www.google.com'] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    public function test_the_policy_is_not_sent_when_disabled(): void
    {
        config(['csp.mode' => 'off']);

        $response = $this->get(route('login'));

        $this->assertFalse($response->headers->has(self::REPORT));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_unknown_mode_never_enforces_anything(): void
    {
        foreach (['bloquear', 'Enforce', 'ENFORCE', 'true', '1', ''] as $modo) {
            config(['csp.mode' => $modo]);

            $response = $this->get(route('login'));

            $this->assertFalse($response->headers->has('Content-Security-Policy'), "Modo «{$modo}» no debe bloquear.");
            $this->assertFalse($response->headers->has(self::REPORT), "Modo «{$modo}» no debe enviar reporte.");
        }
    }

    public function test_enforce_mode_sends_a_blocking_policy_and_no_report_only_header(): void
    {
        config(['csp.mode' => 'enforce']);

        $response = $this->get(route('login'));

        $response->assertOk()->assertHeader('Content-Security-Policy');
        $this->assertFalse($response->headers->has(self::REPORT));

        $policy = (string) $response->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'self'", 'frame-src https://www.google.com'] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    public function test_enforce_and_report_only_use_the_same_policy(): void
    {
        config(['csp.mode' => 'report-only']);
        $reporte = (string) $this->get(route('login'))->headers->get(self::REPORT);

        config(['csp.mode' => 'enforce']);
        $bloqueo = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $bloqueo);
        $this->assertSame($reporte, $bloqueo);
    }

    public function test_enforce_mode_does_not_touch_json_or_redirect_responses(): void
    {
        config(['csp.mode' => 'enforce']);

        $this->assertFalse($this->getJson(route('api.auth.me'))->headers->has('Content-Security-Policy'));
        $this->assertFalse($this->get('/home')->headers->has('Content-Security-Policy'));
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

    public function test_the_vite_dev_origin_is_added_outside_production(): void
    {
        $hot = sys_get_temp_dir().'/horus-hot-'.uniqid();
        file_put_contents($hot, "http://[::1]:5173\n");
        config(['csp.mode' => 'report-only', 'csp.vite_hot_file' => $hot]);

        $policy = (string) $this->get(route('login'))->headers->get(self::REPORT);
        @unlink($hot);

        // `http://[::1]:5173` no es una fuente válida de CSP (el navegador la descarta y bloquea Vite): para una
        // IPv6 literal se permite el esquema, no el origen con corchetes.
        $this->assertStringContainsString('script-src \'self\' \'unsafe-eval\' http:', $policy);
        $this->assertStringContainsString('connect-src \'self\' http: ws:', $policy);
        $this->assertStringNotContainsString('[::1]', $policy);
    }

    public function test_a_named_vite_host_is_limited_to_its_origin(): void
    {
        $hot = sys_get_temp_dir().'/horus-hot-'.uniqid();
        file_put_contents($hot, "http://localhost:5173\n");
        config(['csp.mode' => 'report-only', 'csp.vite_hot_file' => $hot]);

        $policy = (string) $this->get(route('login'))->headers->get(self::REPORT);
        @unlink($hot);

        $this->assertStringContainsString('script-src \'self\' \'unsafe-eval\' http://localhost:5173', $policy);
        $this->assertStringContainsString('connect-src \'self\' http://localhost:5173 ws://localhost:5173', $policy);
        $this->assertStringNotContainsString(' http: ', $policy.' ');
    }

    public function test_a_malformed_hot_file_adds_nothing(): void
    {
        $hot = sys_get_temp_dir().'/horus-hot-'.uniqid();
        file_put_contents($hot, "javascript:alert(1)\n");
        config(['csp.mode' => 'report-only', 'csp.vite_hot_file' => $hot]);

        $policy = (string) $this->get(route('login'))->headers->get(self::REPORT);
        @unlink($hot);

        $this->assertStringNotContainsString('javascript', $policy);
        $this->assertStringNotContainsString('ws://', $policy);
    }

    public function test_the_vite_origin_is_never_added_in_production(): void
    {
        $hot = sys_get_temp_dir().'/horus-hot-'.uniqid();
        file_put_contents($hot, "http://[::1]:5173\n");
        config(['csp.mode' => 'report-only', 'csp.vite_hot_file' => $hot]);
        $this->app['env'] = 'production';

        $policy = (string) $this->get(route('login'))->headers->get(self::REPORT);
        @unlink($hot);

        $this->assertStringNotContainsString('5173', $policy);
    }
}
