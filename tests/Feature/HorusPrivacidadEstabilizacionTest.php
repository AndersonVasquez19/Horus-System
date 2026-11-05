<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use App\Support\FiltroPrivacidadChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorusPrivacidadEstabilizacionTest extends TestCase
{
    use RefreshDatabase;

    public static function bloqueados(): iterable
    {
        $casos = json_decode(file_get_contents(__DIR__.'/../Fixtures/privacidad/forense-compartible.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($casos as $caso) {
            yield $caso['id'] => [$caso['mensaje']];
        }
        foreach ([
            'Mi familiar tiene 17 años.',
            'La edad de mi hija es 16.',
            'Mi número de seguro es 003456.',
            'Mi ficha personal es 004567.',
            'Mi registro privado es 005678.',
            'Mi cuenta particular es 006789.',
            'El visitante dice que su nombre es 张岚祺.',
            'Mi sobrino se identifica como vida puente.',
            'La empresa Nube Zelvani Quenzuli es mi primo.',
            'La empresa Nube y Otrunia Velzunco, mi prima, quieren capacitación.',
            'El visitante se identifica como Zelvacion Qenzuridad.',
            'Mi nombre es Ｚｅｌｖａｎｉ　Ｑｕｅｎｚｕｌｉ.',
            "Mi nombre es Ze\u{200B}lvani Quenzuli.",
            'Quiero cursos de Pуthon',
            'Mi secreto compartido es claveFicticia47.',
            'Llámame al nueve siete cero cero cero cero dos uno seis.',
            "Una duda para consuelo\u{200B}mercado sobre cursos",
        ] as $i => $mensaje) {
            yield 'causa-general-'.$i => [$mensaje];
        }
    }

    #[DataProvider('bloqueados')]
    public function test_bloquea_antes_del_cliente_sin_almacenar_original(string $mensaje): void
    {
        Http::preventStrayRequests();
        $advisor = $this->mock(HorusAdvisorClient::class);
        $advisor->shouldNotReceive('orientar');
        $advisor->shouldReceive('motivoContingencia')->andReturn(null);
        $logs = [];
        Log::listen(function ($event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $respuesta = app(AsistenteVirtualService::class)->procesarMensaje($mensaje, 'regresion');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD, $respuesta['respuesta']);
        Http::assertNothingSent();
        $this->assertSame('[contenido omitido por privacidad]', ChatLog::firstOrFail()->mensaje_usuario);
        $this->assertStringNotContainsString($mensaje, json_encode($logs, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], Cache::getStore()->all());
    }

    public function test_cache_no_omite_filtro_actual(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('lock')->never();
        $this->mock(HorusAdvisorClient::class)->shouldNotReceive('orientar');
        $result = app(AsistenteVirtualService::class)->procesarMensaje('me llamo Zelvani Quenzuli', 'cache');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD, $result['respuesta']);
    }

    public function test_cache_no_autenticada_se_descarta(): void
    {
        config(['horus-advisor.enabled' => false]);
        Cache::flush();
        $service = app(AsistenteVirtualService::class);
        $service->procesarMensaje('¿Qué cursos ofrecen?', 'cache-invalida');
        $entries = Cache::getStore()->all();
        $key = array_key_first($entries);
        Cache::put($key, ['reply' => ['respuesta' => 'Soy Zelvani Quenzuli', 'intent' => null, 'fuente' => 'advisor'], 'mac' => str_repeat('0', 64)], 60);
        $result = $service->procesarMensaje('¿Qué cursos ofrecen?', 'cache-invalida');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $result['respuesta']);
        $this->assertSame(2, ChatLog::count());
    }

    public function test_revalidacion_de_respuestas_cacheadas(): void
    {
        $client = new HorusAdvisorClient;
        $this->assertFalse($client->respuestaCacheValida(['respuesta' => 'Soy Zelvani Quenzuli', 'intent' => null, 'fuente' => 'advisor']));
        $this->assertFalse($client->respuestaCacheValida(['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'intent' => null, 'fuente' => 'inventada']));
        $this->assertFalse($client->respuestaCacheValida(['respuesta' => ['malformada'], 'intent' => null, 'fuente' => 'advisor']));
        $this->assertTrue($client->respuestaCacheValida(['respuesta' => AsistenteVirtualService::RESPUESTA_FALLBACK, 'intent' => null, 'fuente' => 'respaldo']));
        foreach (['Mi DNI es 74321980', 'Mi correo es demo@example.invalid', 'Mi hija tiene 17 años.', 'Mi historia clínica es 003456'] as $texto) {
            $this->assertFalse($client->respuestaCacheValida(['respuesta' => $texto, 'intent' => null, 'fuente' => 'advisor']));
        }
    }

    public function test_cache_caliente_deduplica_y_rechaza_texto_personal_incluso_con_mac_valido(): void
    {
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => false]);
        Cache::flush();
        $service = app(AsistenteVirtualService::class);
        $mensaje = '¿Qué cursos ofrecen?';
        $primera = $service->procesarMensaje($mensaje, 'caliente');
        $this->assertSame($primera, $service->procesarMensaje($mensaje, 'caliente'));
        $this->assertSame(1, ChatLog::count());
        $entries = Cache::getStore()->all();
        $key = array_key_first($entries);
        $reply = ['respuesta' => 'Soy Zelvani Quenzuli', 'intent' => null, 'fuente' => 'advisor'];
        Cache::put($key, ['reply' => $reply, 'mac' => hash_hmac('sha256', $key.serialize($reply), (string) config('app.key'))], 60);
        $this->assertSame($primera, $service->procesarMensaje($mensaje, 'caliente'));
        $this->assertSame(2, ChatLog::count());
        Http::assertNothingSent();
    }

    public function test_lock_ocupado_no_transmite_y_no_oculta_privacidad(): void
    {
        Http::preventStrayRequests();
        $session = hash_hmac('sha256', 'direct|ocupada', (string) config('app.key'));
        $lock = Cache::lock('chat:lock:'.$session, 30);
        $this->assertTrue($lock->get());
        try {
            $service = app(AsistenteVirtualService::class);
            $this->assertSame('ocupado', $service->procesarMensaje('¿Qué cursos ofrecen?', 'ocupada')['fuente']);
            $this->assertSame(AsistenteVirtualService::RESPUESTA_PRIVACIDAD, $service->procesarMensaje('me llamo Zelvani Quenzuli', 'ocupada')['respuesta']);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_presupuesto_agotado_no_inicia_http(): void
    {
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32)]);
        $tiempo = -3.0;
        $client = new HorusAdvisorClient(reloj: static function () use (&$tiempo): float {
            return $tiempo += 3.0;
        });
        $this->assertNull($client->orientar('¿Qué cursos ofrecen?'));
        Http::assertNothingSent();
    }

    public function test_profesiones_y_consultas_publicas_no_son_datos_personales(): void
    {
        $filtro = new FiltroPrivacidadChat;
        foreach ([
            'Soy estudiante y quiero aprender redes.',
            '¿El profesor es especialista en redes?',
            'El alumno es principiante.',
            '¿Qué cursos de Microsoft Excel ofrecen?',
            '¿Capacitan a profesores y asesores?',
            '¿Cuál es el RUC de Horus Group?',
            '¿Qué duración tienen las capacitaciones empresariales?',
            '¿Hacen mantenimiento de equipos para instituciones?',
            'Mi familiar pregunta si tienen cursos de tres años de duración.',
            'Hola 👨‍👩‍👧‍👦, ¿qué cursos ofrecen?',
        ] as $mensaje) {
            $this->assertFalse($filtro->contieneDatosPersonales($mensaje), $mensaje);
        }
    }

    public function test_presupuesto_compartido_agotado_despues_de_ready_impide_post(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32)]);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:8001/ready' => Http::response([
            'status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured', 'catalog' => 'none', 'semantic' => 'disabled',
        ])]);
        $tiempos = [0.0, 0.001, 3.0];
        $client = new HorusAdvisorClient(reloj: static function () use (&$tiempos): float {
            return array_shift($tiempos);
        });
        $this->assertNull($client->orientar('¿Qué cursos ofrecen?'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
