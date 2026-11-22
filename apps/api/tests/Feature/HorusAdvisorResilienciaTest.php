<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\FuentesCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Causa tipificada de cada fallo, circuito ante fallos seguidos y lista blanca de hosts privados (22/11/2026). */
class HorusAdvisorResilienciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.allowed_hosts' => []]);
        $segundos = 0.0;
        $this->app->instance(HorusAdvisorClient::class, new HorusAdvisorClient(reloj: static function () use (&$segundos): float {
            return $segundos += 0.001;
        }));
    }

    public static function causas(): iterable
    {
        yield 'conexión rechazada' => ['conexion', 'conexion'];
        yield 'tiempo agotado (cURL 28)' => ['tiempo_agotado', 'tiempo_agotado'];
        yield '503' => [503, 'http_5xx'];
        yield '401' => [401, 'http_4xx'];
        yield 'tipo de contenido' => ['content_type', 'tipo_contenido'];
        yield 'json roto' => ['malformed', 'json_invalido'];
        yield 'lista json' => ['list', 'json_invalido'];
        yield 'respuesta excesiva' => ['oversize', 'respuesta_excesiva'];
        yield 'no listo' => ['not_ready', 'no_listo'];
        yield 'validación' => ['pii', 'validacion'];
    }

    #[DataProvider('causas')]
    public function test_cada_fallo_tiene_una_causa_tipificada(int|string $caso, string $causa): void
    {
        $this->fake($caso);
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar('Orientame sobre cursos'));
        $this->assertSame($causa, $client->causaFallo());
        $this->assertIsInt($client->latenciaMs());
    }

    public function test_configuracion_invalida_se_tipifica_sin_red(): void
    {
        config(['horus-advisor.url' => 'http://169.254.169.254:80']);
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar('Orientame sobre cursos'));
        $this->assertSame('configuracion', $client->causaFallo());
        Http::assertNothingSent();
    }

    public function test_exito_no_deja_causa(): void
    {
        $this->fake();
        $client = app(HorusAdvisorClient::class);
        $this->assertNotNull($client->orientar('Orientame sobre cursos'));
        $this->assertNull($client->causaFallo());
    }

    public function test_el_registro_solo_lleva_categoria_y_latencia(): void
    {
        Log::spy();
        $this->fake('conexion');
        app(AsistenteVirtualService::class)->procesarMensaje('Orientame sobre cursos', 'sesion-registro', 'public:browser');
        Log::shouldHaveReceived('info')->withArgs(static fn (string $evento, array $datos): bool => $evento === 'chatbot.advisor_fallo'
            && array_keys($datos) === ['causa', 'latencia_ms'] && $datos['causa'] === 'conexion' && is_int($datos['latencia_ms']))->once();
        Log::shouldNotHaveReceived('info', [\Mockery::on(static fn (string $evento): bool => $evento === 'chatbot.advisor_fallo'), \Mockery::on(
            static fn (array $datos): bool => str_contains(json_encode($datos, JSON_THROW_ON_ERROR), 'Orientame'))]);
    }

    public function test_el_circuito_se_abre_tras_tres_fallos_de_transporte_y_no_llama_mas(): void
    {
        Log::spy();
        $this->fake('conexion');
        $client = app(HorusAdvisorClient::class);
        foreach (range(1, 3) as $_) {
            $this->assertNull($client->orientar('Orientame sobre cursos'));
        }
        $enviados = count(Http::recorded());
        $this->assertNull($client->orientar('Orientame sobre cursos'));
        $this->assertSame('circuito_abierto', $client->causaFallo());
        $this->assertSame('no_disponible', $client->motivoContingencia());
        $this->assertCount($enviados, Http::recorded(), 'Con el circuito abierto no se llama a Horus AI.');
        Log::shouldHaveReceived('warning')->with('chatbot.circuito_abierto', ['segundos' => 30, 'causa' => 'conexion'])->once();
    }

    public function test_el_circuito_se_cierra_al_vencer_y_vuelve_a_llamar(): void
    {
        $this->fake(503);
        $client = app(HorusAdvisorClient::class);
        foreach (range(1, 3) as $_) {
            $client->orientar('Orientame sobre cursos');
        }
        $this->travel(31)->seconds();
        $this->fake();
        $this->assertNotNull($client->orientar('Orientame sobre cursos'));
    }

    public function test_los_rechazos_de_validacion_no_abren_el_circuito(): void
    {
        $this->fake('pii');
        $client = app(HorusAdvisorClient::class);
        foreach (range(1, 4) as $_) {
            $client->orientar('Orientame sobre cursos');
        }
        $this->assertSame('validacion', $client->causaFallo());
        Http::assertSentCount(8); // ready + chat en cada intento: el circuito sigue cerrado
    }

    public function test_un_exito_reinicia_la_cuenta_de_fallos(): void
    {
        $client = app(HorusAdvisorClient::class);
        foreach (['conexion', 'conexion', null, 'conexion', 'conexion'] as $caso) {
            $this->fake($caso);
            $client->orientar('Orientame sobre cursos');
        }
        $this->fake();
        $this->assertNotNull($client->orientar('Orientame sobre cursos'));
    }

    public function test_catalogo_caducado_sigue_atendiendo_con_el_conocimiento_institucional(): void
    {
        $this->fake('stale');
        $client = app(HorusAdvisorClient::class);
        $this->assertNotNull($client->orientar('Orientame sobre cursos'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/chat'));
    }

    public function test_catalogo_caducado_rechaza_respuestas_que_citan_un_curso_aunque_php_lo_tenga(): void
    {
        $curso = ['id' => 'cat_curso_publicacion_1', 'title' => 'Curso de prueba', 'url_path' => '/educacion/cursos'];
        $texto = 'Curso de prueba: resumen público del curso de prueba.';
        // Aunque la reconstrucción local tuviera la ficha, con el catálogo caducado no se usa.
        $this->app->instance(FuentesCatalogoAdvisor::class, new class($curso, $texto) extends FuentesCatalogoAdvisor
        {
            /** @param array{id: string, title: string, url_path: string} $curso */
            public function __construct(private array $curso, private string $texto) {}

            public function actuales(): array
            {
                return [$this->curso['id'] => ['title' => $this->curso['title'], 'url_path' => $this->curso['url_path'], 'answer' => $this->texto, 'fragments' => [$this->texto]]];
            }
        });
        $this->respuestaCurso = array_replace($this->reply(), ['answer' => $texto, 'sources' => [$curso]]);
        $this->fake('stale_curso');
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar('Orientame sobre cursos'));
        $this->assertSame('respuesta_rechazada', $client->motivoContingencia());
        $this->assertSame('validacion', $client->causaFallo());
    }

    public function test_host_privado_autorizado_recibe_la_consulta(): void
    {
        config(['horus-advisor.url' => 'http://horus-ai.railway.internal:8001', 'horus-advisor.allowed_hosts' => ['horus-ai.railway.internal']]);
        $this->fake();
        $this->assertNotNull(app(HorusAdvisorClient::class)->orientar('Orientame sobre cursos'));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://horus-ai.railway.internal:8001/api/v1/chat');
    }

    public static function destinosRechazados(): iterable
    {
        yield 'host no autorizado' => ['http://otro.railway.internal:8001', ['horus-ai.railway.internal']];
        yield 'lista vacía' => ['http://horus-ai.railway.internal:8001', []];
        yield 'IP privada aunque se autorice' => ['http://10.0.0.5:8001', ['10.0.0.5']];
        yield 'metadatos aunque se autoricen' => ['http://metadata.google.internal:80', ['metadata.google.internal']];
        yield 'localhost por nombre' => ['http://localhost:8001', ['localhost']];
        yield 'https no admitido' => ['https://horus-ai.railway.internal:8001', ['horus-ai.railway.internal']];
        yield 'userinfo' => ['http://horus-ai.railway.internal:8001@evil.invalid', ['horus-ai.railway.internal']];
        yield 'ruta' => ['http://horus-ai.railway.internal:8001/x', ['horus-ai.railway.internal']];
        yield 'sin puerto' => ['http://horus-ai.railway.internal', ['horus-ai.railway.internal']];
        yield 'puerto fuera de rango' => ['http://horus-ai.railway.internal:70000', ['horus-ai.railway.internal']];
        yield 'mayúsculas' => ['http://HORUS-AI.railway.internal:8001', ['horus-ai.railway.internal']];
    }

    /** @param list<string> $hosts */
    #[DataProvider('destinosRechazados')]
    public function test_destinos_no_autorizados_se_rechazan_antes_de_la_red(string $url, array $hosts): void
    {
        config(['horus-advisor.url' => $url, 'horus-advisor.allowed_hosts' => $hosts]);
        $client = app(HorusAdvisorClient::class);
        $this->assertNull($client->orientar('Orientame sobre cursos'));
        $this->assertSame('configuracion', $client->causaFallo());
        Http::assertNothingSent();
    }

    private int|string|null $caso = null;

    /** @var array<string, mixed> */
    private array $respuestaCurso = [];

    private bool $falsoRegistrado = false;

    /** Http::fake acumula callbacks y gana el primero: se registra una vez y el caso se cambia aquí. */
    private function fake(int|string|null $caso = null): void
    {
        $this->caso = $caso;
        if ($this->falsoRegistrado) {
            return;
        }
        $this->falsoRegistrado = true;
        Http::fake(function (Request $request) {
            $caso = $this->caso;
            if (str_ends_with($request->url(), '/ready')) {
                return Http::response(match ($caso) {
                    'not_ready' => array_replace($this->ready(), ['status' => 'not_ready']),
                    'stale', 'stale_curso' => array_replace($this->ready(), ['catalog' => 'stale']),
                    default => $this->ready(),
                });
            }
            if ($caso === 'stale_curso') {
                return Http::response($this->respuestaCurso);
            }
            if ($caso === 'conexion') {
                throw new ConnectionException('Detalle interno que nunca se registra');
            }
            if ($caso === 'tiempo_agotado') {
                $interna = new ConnectException('Detalle interno', new PsrRequest('POST', $request->url()), null, ['errno' => 28]);
                throw new ConnectionException('Detalle interno que nunca se registra', 0, $interna);
            }
            if (is_int($caso)) {
                return Http::response(['error' => 'Detalle privado'], $caso);
            }

            return match ($caso) {
                'content_type' => Http::response(json_encode($this->reply()), 200, ['Content-Type' => 'text/html']),
                'malformed' => Http::response('{roto', 200, ['Content-Type' => 'application/json']),
                'list' => Http::response([1, 2, 3]),
                'oversize' => Http::response(str_repeat('x', 65537)),
                'pii' => Http::response(array_replace($this->reply(), ['answer' => 'Datos ana@example.invalid'])),
                default => Http::response($this->reply()),
            };
        });
    }

    /** @return array<string, string> */
    private function ready(): array
    {
        return ['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured', 'semantic' => 'disabled', 'catalog' => 'none'];
    }

    /** @return array<string, mixed> */
    private function reply(): array
    {
        return ['answer' => 'Horus ofrece orientación sobre cursos y formación. Consulta /contactos.', 'intent' => 'recommendation', 'mode' => 'lexical', 'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false, 'recommendations' => [], 'sources' => [['id' => 'formacion', 'title' => 'Formación', 'url_path' => '/educacion/cursos']]];
    }
}
