<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Nombres completos en minúsculas: bloqueo antes de cualquier HTTP y sin rastro en ChatLog ni logs. */
class HorusPrivacidadNombresTest extends TestCase
{
    use RefreshDatabase;

    private const RESPUESTA_PRIVACIDAD = 'Parece que incluiste un nombre o dato personal. Por seguridad, evita compartir información personal. Si deseas consultar sobre un curso o servicio, puedes reformular tu pregunta sin esos datos.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32)]);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function entradasConNombre(): iterable
    {
        yield 'soy anderson vela' => ['soy anderson vela', ['anderson', 'vela']];
        yield 'soy anderson vela y quiero matricularme' => ['soy anderson vela y quiero matricularme', ['anderson', 'vela']];
        yield 'matricula a yuli huatay cieza en excel' => ['matricula a yuli huatay cieza en excel', ['yuli', 'huatay', 'cieza']];
        yield 'brayan cuzco tello necesita soporte' => ['brayan cuzco tello necesita soporte', ['brayan', 'cuzco', 'tello']];
        yield 'quiero inscribir a kelly sánchez rojas' => ['quiero inscribir a kelly sánchez rojas', ['kelly', 'rojas']];
        yield 'mi nombre es anderson vela' => ['mi nombre es anderson vela', ['anderson', 'vela']];
        yield 'me llamo yuli huatay' => ['me llamo yuli huatay', ['yuli', 'huatay']];
        yield 'soy carlos mendoza' => ['soy carlos mendoza', ['carlos', 'mendoza']];
        yield 'inscribe a maria fernanda lopez' => ['inscribe a maria fernanda lopez', ['fernanda', 'lopez']];
        yield 'dni' => ['mi dni es 45879632', ['45879632']];
        yield 'telefono' => ['mi celular es 987654321', ['987654321']];
        yield 'correo' => ['mi correo es kelly.rojas@gmail.com', ['kelly']];
        yield 'direccion privada' => ['vivo en jr. amazonas 450', ['amazonas']];
        yield 'credencial' => ['mi contraseña es Huatay2026!', ['huatay']];
    }

    /** @param list<string> $fragmentos */
    #[DataProvider('entradasConNombre')]
    public function test_bloquea_sin_http_y_sin_guardar_el_dato(string $q, array $fragmentos): void
    {
        $logs = [];
        Log::listen(function ($evento) use (&$logs): void {
            $logs[] = $evento->message.' '.json_encode($evento->context, JSON_UNESCAPED_UNICODE);
        });

        $resultado = app(AsistenteVirtualService::class)->procesarMensaje($q, 'privacidad-nombres');

        $this->assertSame('respaldo', $resultado['fuente'], $q);
        $this->assertSame(self::RESPUESTA_PRIVACIDAD, $resultado['respuesta']);
        Http::assertNothingSent();
        $registro = ChatLog::query()->sole();
        $this->assertSame('[contenido omitido por privacidad]', $registro->mensaje_usuario);
        $guardado = mb_strtolower(json_encode($registro->getAttributes(), JSON_UNESCAPED_UNICODE).' '.implode(' ', $logs));
        foreach ($fragmentos as $fragmento) {
            $this->assertStringNotContainsString($fragmento, $guardado, $q);
        }
        $this->assertStringContainsString('privacidad', implode(' ', $logs));
    }

    /** @return iterable<string, array{string}> */
    public static function consultasLegitimas(): iterable
    {
        foreach (['¿Tienen cursos de Microsoft Excel?', 'Curso de Power BI.', 'Ofimática Básica.', '¿Tienen cursos para estudiantes?',
            '¿Hacen cableado en San Marcos?', '¿Dónde están ubicados?', '¿Aceptan pago con BCP?', '¿Qué convenios tienen?',
            '¿Ofrecen Wi-Fi?', '¿Cómo puedo matricularme?'] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('consultasLegitimas')]
    public function test_consultas_legitimas_llegan_a_python(string $q): void
    {
        $kb = json_decode((string) file_get_contents(__DIR__.'/../../chatbot-python/data/knowledge.json'), true, 512, JSON_THROW_ON_ERROR);
        $record = collect($kb['records'])->firstWhere('id', 'servicio_soporte');
        Http::fake([
            '*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured', 'catalog' => 'none', 'semantic' => 'disabled']),
            '*/api/v1/chat' => Http::response([
                'answer' => $record['answer'], 'intent' => 'service_recommendation',
                'mode' => 'lexical', 'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false,
                'clarification_question' => null, 'recommendations' => [], 'actions' => [],
                'sources' => [['id' => $record['id'], 'title' => $record['title'], 'url_path' => $record['source']['url_path']]],
            ]),
        ]);

        $this->assertSame('advisor', app(AsistenteVirtualService::class)->procesarMensaje($q, 'legitima')['fuente'], $q);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ! str_ends_with($r->url(), '/api/v1/chat') || ($r['history'] === [] && ! isset($r['session_id'])));
    }
}
