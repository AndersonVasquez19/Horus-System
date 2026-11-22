<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Estado conversacional (22/11/2026). Laravel no guarda ni reenvía el texto de la conversación: guarda 5 minutos,
 * firmado con HMAC, el `state` estructurado que devolvió Horus AI (claves de vocabulario cerrado, registros en foco y
 * contadores) y lo reenvía en el turno siguiente. Un estado con cualquier forma ajena se descarta entero.
 */
class AsistenteEstadoConversacionalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> Respuesta real de Horus AI («que asesoria legal tienen»), con su estado. */
    private array $respuesta;

    /** @var list<array<string, mixed>> Cuerpo de cada POST /api/v1/chat recibido. */
    private array $enviados = [];

    /** @var list<array<string, mixed>> Respuestas que devolverá Horus AI, en orden (la última se repite). */
    private array $cola = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        Http::preventStrayRequests();
        $casos = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-comprension-replies.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->respuesta = $casos[0]['reply'];
        $this->cola = [$this->respuesta];
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/ready')) {
                return Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
                    'semantic' => 'disabled', 'catalog' => 'loaded', 'catalog_version' => str_repeat('a', 40)]);
            }
            $this->enviados[] = $request->data();

            return Http::response(count($this->cola) > 1 ? array_shift($this->cola) : $this->cola[0]);
        });
    }

    public function test_el_segundo_turno_reenvia_el_estado_sin_historial_de_texto(): void
    {
        $servicio = app(AsistenteVirtualService::class);
        $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-estado');
        $servicio->procesarMensaje('¿cuánto dura?', 'sesion-estado');

        $this->assertCount(2, $this->enviados);
        $this->assertArrayNotHasKey('state', $this->enviados[0]);
        $this->assertSame([], $this->enviados[1]['history']);
        $this->assertEquals($this->respuesta['state'], $this->enviados[1]['state']);
        // Con estado, Horus AI resuelve el seguimiento: el mensaje viaja tal cual, sin reescribirlo en PHP.
        $this->assertSame('¿cuánto dura?', $this->enviados[1]['message']);
    }

    public function test_elegir_entre_opciones_se_sigue_resolviendo_con_estado(): void
    {
        $opciones = [...$this->respuesta, 'answer' => 'Encontré opciones relacionadas.', 'intent' => 'clarification',
            'match_level' => 'baja', 'needs_clarification' => true, 'clarification_question' => '¿Cuál te interesa?',
            'sources' => [], 'context' => [...$this->respuesta['context'], 'focus_ids' => ['asesoramiento_legal', 'asesoramiento_empresarial']]];
        $this->cola = [$opciones, $this->respuesta];
        $servicio = app(AsistenteVirtualService::class);
        $servicio->procesarMensaje('asesoria', 'sesion-opciones');
        $servicio->procesarMensaje('la segunda', 'sesion-opciones');

        $this->assertSame('Información sobre Asesoramiento empresarial', $this->enviados[1]['message']);
        $this->assertArrayHasKey('state', $this->enviados[1]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function estadosAjenos(): iterable
    {
        yield 'texto libre en un tema' => [['topics' => ['quiero aprender redes desde cero']]];
        yield 'clave desconocida' => [['texto' => 'lo que escribió el usuario']];
        yield 'registro inexistente' => [['focus_ids' => ['registro_inventado']]];
        yield 'demasiados registros' => [['focus_ids' => ['asesoramiento_legal', 'asesoramiento_empresarial', 'contacto_canales']]];
        yield 'edad fuera de rango' => [['profile_age' => 7]];
        yield 'objetivo ajeno' => [['goal' => 'comprar']];
        yield 'tipo incorrecto' => [['quote' => 'sí']];
        yield 'lista en vez de mapa' => [['state' => ['legal']]];
    }

    /** @param array<string, mixed> $cambio */
    #[DataProvider('estadosAjenos')]
    public function test_un_estado_con_forma_ajena_se_descarta_entero(array $cambio): void
    {
        $estado = array_key_exists('state', $cambio) ? $cambio['state'] : [...$this->respuesta['state'], ...$cambio];
        $this->cola = [[...$this->respuesta, 'state' => $estado]];
        $servicio = app(AsistenteVirtualService::class);
        $primera = $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-ajena');
        $this->assertSame('advisor', $primera['fuente'], 'El estado ajeno no invalida la respuesta, solo se descarta.');
        $servicio->procesarMensaje('¿y es virtual?', 'sesion-ajena');

        $this->assertArrayNotHasKey('state', $this->enviados[1]);
    }

    public function test_una_contingencia_borra_el_estado(): void
    {
        $servicio = app(AsistenteVirtualService::class);
        $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-caida');
        $this->cola = [['respuesta' => 'no es un contrato válido']];
        $this->assertSame('respaldo', $servicio->procesarMensaje('¿y cuánto dura?', 'sesion-caida')['fuente']);
        $this->cola = [$this->respuesta];
        $servicio->procesarMensaje('¿y es virtual?', 'sesion-caida');

        $this->assertArrayNotHasKey('state', $this->enviados[2]);
    }

    public function test_la_misma_frase_en_otro_contexto_no_reutiliza_la_respuesta_pero_el_doble_envio_si(): void
    {
        $servicio = app(AsistenteVirtualService::class);
        $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-dedupe');
        $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-dedupe');
        $this->assertCount(1, $this->enviados, 'El doble envío inmediato se deduplica.');

        $otro = [...$this->respuesta['state'], 'focus_ids' => ['asesoramiento_empresarial']];
        $this->cola = [[...$this->respuesta, 'state' => $otro]];
        $servicio->procesarMensaje('asesoramiento empresarial', 'sesion-dedupe');
        $servicio->procesarMensaje('¿cuánto dura?', 'sesion-dedupe');
        $this->cola = [$this->respuesta];
        $servicio->procesarMensaje('que asesoria legal tienen', 'sesion-dedupe');
        $servicio->procesarMensaje('¿cuánto dura?', 'sesion-dedupe');

        $this->assertCount(5, $this->enviados, '«¿cuánto dura?» sobre otro tema es otra consulta.');
        $this->assertSame(['asesoramiento_empresarial'], $this->enviados[2]['state']['focus_ids']);
        $this->assertSame(['asesoramiento_legal'], $this->enviados[4]['state']['focus_ids']);
    }

    public function test_la_cache_no_guarda_el_texto_del_visitante(): void
    {
        $servicio = app(AsistenteVirtualService::class);
        $servicio->procesarMensaje('que asesoria legal tienen Zacarías', 'sesion-privada');
        $servicio->procesarMensaje('¿y cuánto dura el de Zacarías?', 'sesion-privada');

        $volcado = serialize(Cache::getStore()->all());
        $this->assertStringNotContainsString('Zacar', $volcado);
        $this->assertStringNotContainsString('cuánto dura', $volcado);
        $this->assertStringContainsString('"legal"', json_encode($this->enviados[1]['state'], JSON_THROW_ON_ERROR));
    }
}
