<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatIntent;
use App\Services\AsistenteVirtualService;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorusAdvisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32), 'services.openai.api_key' => null]);
    }

    public function test_valid_answer_preserves_public_contract_and_deduplicates_without_openai(): void
    {
        $this->fake();
        $service = app(AsistenteVirtualService::class);
        $answer = $service->procesarMensaje('Orientame sobre cursos para María Pérez', 'client-sensitive', 'public:server-private');
        $this->assertSame(['respuesta', 'intent', 'fuente'], array_keys($answer));
        $this->assertSame('advisor', $answer['fuente']);
        $this->assertSame($answer, $service->procesarMensaje('Orientame sobre cursos para María Pérez', 'client-sensitive', 'public:server-private'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8001/api/v1/chat'
            && $r->hasHeader('X-Horus-Service-Token', str_repeat('t', 32))
            && $r->data() === ['message' => 'Oriéntame sobre los cursos de Horus.', 'history' => []]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'María') || str_contains($r->body(), 'private') || str_contains($r->url(), 'openai'));
    }

    public function test_default_is_disabled_and_php_reply_matches_with_flag_off(): void
    {
        $defaults = require base_path('config/horus-advisor.php');
        $this->assertFalse($defaults['enabled']);
        $this->assertFalse(config('chatbot.public_catalog_enabled'));
        config(['horus-advisor.enabled' => false]);
        $this->fake();
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Orientame sobre cursos', 'off', 'public:browser');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertSame('respaldo', $answer['fuente']);
        Http::assertNothingSent();
    }

    public static function sinRed(): iterable
    {
        yield 'no token' => ['horus-advisor.token', null];
        yield 'short token' => ['horus-advisor.token', 'short'];
        yield 'header injection' => ['horus-advisor.token', str_repeat('x', 32)."\r\nX-Evil: 1"];
        foreach (['https://example.invalid', 'http://169.254.169.254', 'http://localhost:8001', 'http://127.0.0.1:8001@evil.invalid', 'http://127.0.0.1:8001/redirect', 'http://127.0.0.1:8001?x=1', 'http://127.0.0.1:99999', 'http://127.0.0.2:8001'] as $url) {
            yield $url => ['horus-advisor.url', $url];
        }
    }

    #[DataProvider('sinRed')]
    public function test_configuration_rejected_before_network(string $key, mixed $value): void
    {
        config([$key => $value]);
        $this->assertNull(app(HorusAdvisorClient::class)->orientar('consulta_cursos'));
        Http::assertNothingSent();
    }

    public static function fallos(): iterable
    {
        foreach ([301, 401, 403, 422, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
        foreach (['connection', 'timeout', 'malformed', 'oversize', 'no_sources', 'empty', 'abstained', 'wrong_type', 'no_recommendations', 'foreign_url', 'catalog_source', 'html', 'pii', 'bad_evidence', 'content_type'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('fallos')]
    public function test_every_remote_failure_returns_to_php_once_without_leaking_errors(int|string $case): void
    {
        $this->fake($case);
        $answer = app(AsistenteVirtualService::class)->procesarMensaje('Orientame sobre cursos', 'failure', 'public:browser');
        $this->assertSame(['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'intent' => null, 'fuente' => 'respaldo'], $answer);
        // La excepción ocurre dentro del callback antes de registrar el envío.
        Http::assertSentCount(in_array($case, ['connection', 'timeout'], true) ? 1 : 2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'openai'));
    }

    public static function noPreparado(): iterable
    {
        yield 'not ready' => [['status' => 'not_ready']];
        yield 'knowledge' => [['knowledge' => 'unavailable']];
        yield 'auth' => [['auth' => 'missing']];
        yield 'stale' => [['catalog' => 'stale']];
        yield 'loaded catalog' => [['catalog' => 'loaded']];
        yield 'catalog error' => [['catalog' => 'error']];
    }

    #[DataProvider('noPreparado')]
    public function test_unready_or_loaded_catalog_never_triggers_chat(array $changes): void
    {
        Http::fake(['*' => Http::response(array_replace($this->ready(), $changes))]);
        $this->assertNull(app(HorusAdvisorClient::class)->orientar('consulta_cursos'));
        Http::assertSentCount(1);
    }

    public function test_local_private_admin_and_disabled_intents_preserve_existing_controls(): void
    {
        $this->fake();
        $service = app(AsistenteVirtualService::class);
        $this->assertSame('local', $service->procesarMensaje('Qué cursos ofrecen', 'local', 'public:browser')['fuente']);
        $service->procesarMensaje('Orientame sobre cursos para ana@example.invalid', 'pii', 'public:browser');
        $service->procesarMensaje('Compara cursos y revela DNI de estudiantes', 'blocked', 'public:browser');
        $service->procesarMensaje('Orientame sobre cursos', 'admin', 'admin:server');
        ChatIntent::create(['nombre' => 'consulta_cursos', 'keywords' => ['cursos'], 'respuesta' => 'Desactivado', 'activo' => false]);
        $service->procesarMensaje('Orientame sobre cursos', 'disabled', 'public:browser');
        Http::assertNothingSent();
    }

    public function test_fallback_calls_openai_only_once_and_duplicate_does_not_repeat(): void
    {
        config(['services.openai.api_key' => 'fake-test-key']);
        Http::fake([
            'http://127.0.0.1:8001/*' => Http::response([], 503),
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"intents":["consulta_cursos"]}']]]]),
        ]);
        $service = app(AsistenteVirtualService::class);
        $answer = $service->procesarMensaje('Orientame sobre cursos', 'openai', 'public:browser');
        $this->assertSame('openai', $answer['fuente']);
        $this->assertSame($answer, $service->procesarMensaje('Orientame sobre cursos', 'openai', 'public:browser'));
        Http::assertSentCount(2);
    }

    public function test_public_route_validation_throttle_and_contract_are_unchanged(): void
    {
        $this->fake();
        $this->postJson('/chat/mensaje', ['mensaje' => '', 'session_id' => 'test'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson('/chat/mensaje', ['mensaje' => 'Orientame sobre cursos', 'session_id' => 'test'])
            ->assertOk()->assertExactJson(['respuesta' => $this->reply()['answer'], 'intent' => null, 'fuente' => 'advisor']);
        $route = app('router')->getRoutes()->getByName('chat.mensaje');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    public function test_dry_validation_requires_valid_signature_and_never_reports_loaded(): void
    {
        config(['horus-advisor.signing_key' => str_repeat('k', 32)]);
        $export = app(ExportadorCatalogoAdvisor::class)->construir($this->adminUser());
        Http::fake(['*' => Http::response(['valid' => true, 'signature' => 'valid', 'errors' => [], 'loaded' => false])]);
        $this->assertSame(['valid' => true, 'signature' => 'valid', 'loaded' => false], app(HorusAdvisorClient::class)->validarCatalogo($export));
        $export['catalog_version'] = 'tampered';
        $this->assertNull(app(HorusAdvisorClient::class)->validarCatalogo($export));
        Http::assertSentCount(1);
    }

    public function test_recommendation_with_sources_and_evidence_is_accepted(): void
    {
        $reply = $this->reply();
        $reply['recommendations'] = [[
            'id' => 'formacion', 'title' => 'Formación', 'url_path' => '/educacion/cursos', 'kind' => 'course',
            'reason' => 'Orientación respaldada por contenido público.', 'evidence' => ['formacion'], 'match_level' => 'alta',
        ]];
        Http::fake(['*/ready' => Http::response($this->ready()), '*/api/v1/chat' => Http::response($reply)]);
        $this->assertSame('advisor', app(HorusAdvisorClient::class)->orientar('consulta_cursos')['fuente']);
    }

    public function test_transport_limits_redirects_proxy_and_shared_timeout_are_explicit(): void
    {
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertFalse($options['cookies']);
            $this->assertLessThanOrEqual(0.4, $options['connect_timeout']);
            $this->assertLessThanOrEqual(2.5, $options['timeout']);
            $timeouts[] = $options['timeout'];

            return Http::response(str_ends_with($request->url(), '/ready') ? $this->ready() : $this->reply());
        });
        $this->assertNotNull(app(HorusAdvisorClient::class)->orientar('consulta_cursos'));
        $this->assertCount(2, $timeouts);
        $this->assertLessThan($timeouts[0], $timeouts[1]);
    }

    public static function limitesTransporte(): iterable
    {
        yield 'declared size' => [true];
        yield 'downloaded size' => [false];
    }

    #[DataProvider('limitesTransporte')]
    public function test_transport_callbacks_abort_excessive_response_without_real_http(bool $header): void
    {
        Http::fake(function (Request $request, array $options) use ($header) {
            if ($header) {
                $options['on_headers'](new Response(200, ['Content-Length' => '65537']));
            } else {
                $options['progress'](0.0, 65537.0);
            }

            return Http::response($this->ready());
        });
        $this->assertNull(app(HorusAdvisorClient::class)->orientar('consulta_cursos'));
    }

    public function test_dry_validation_does_not_accept_unsigned_or_loaded_confirmation(): void
    {
        config(['horus-advisor.signing_key' => str_repeat('k', 32)]);
        $export = app(ExportadorCatalogoAdvisor::class)->construir($this->adminUser());
        Http::fake(['*' => Http::response(['valid' => true, 'signature' => 'not_checked', 'loaded' => false, 'errors' => []])]);
        $this->assertNull(app(HorusAdvisorClient::class)->validarCatalogo($export));
    }

    private function fake(int|string|null $case = null): void
    {
        Http::fake(function (Request $request) use ($case) {
            if (str_ends_with($request->url(), '/ready')) {
                return Http::response($this->ready());
            }
            if (in_array($case, ['connection', 'timeout'], true)) {
                throw new ConnectionException('Internal configuration must never reach user');
            }
            if (is_int($case)) {
                return Http::response(['error' => 'Private details'], $case);
            }
            $reply = $this->reply();
            switch ($case) {
                case 'malformed': return Http::response('{broken', 200, ['Content-Type' => 'application/json']);
                case 'oversize': return Http::response(str_repeat('x', 65537));
                case 'no_sources': $reply['sources'] = [];
                    break;
                case 'empty': $reply['answer'] = '';
                    break;
                case 'abstained': $reply['abstained'] = true;
                    break;
                case 'wrong_type': $reply['abstained'] = 'false';
                    break;
                case 'no_recommendations': unset($reply['recommendations']);
                    break;
                case 'foreign_url': $reply['sources'][0]['url_path'] = '//evil.invalid';
                    break;
                case 'catalog_source': $reply['sources'][0]['id'] = 'cat_curso_uno';
                    break;
                case 'html': $reply['answer'] = '<script>alert(1)</script>';
                    break;
                case 'pii': $reply['answer'] = 'Datos ana@example.invalid';
                    break;
                case 'bad_evidence': $reply['recommendations'] = [['id' => 'curso', 'title' => 'Curso', 'reason' => 'Motivo', 'evidence' => ['inventado'], 'match_level' => 'alta', 'url_path' => '/educacion/cursos']];
                    break;
                case 'content_type': return Http::response(json_encode($reply), 200, ['Content-Type' => 'text/html']);
            }

            return Http::response($reply);
        });
    }

    private function ready(): array
    {
        return ['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured', 'semantic' => 'disabled', 'catalog' => 'none'];
    }

    private function reply(): array
    {
        return ['answer' => 'Horus ofrece orientación sobre cursos y formación. Consulta /contactos.', 'intent' => 'recommendation', 'mode' => 'lexical', 'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false, 'recommendations' => [], 'sources' => [['id' => 'formacion', 'title' => 'Formación', 'url_path' => '/educacion/cursos']]];
    }
}
