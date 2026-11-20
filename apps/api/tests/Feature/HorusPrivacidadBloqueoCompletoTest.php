<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Política de BLOQUEO COMPLETO sobre el corpus de desarrollo: todo mensaje etiquetado «B» (contexto, identificadores,
 * seguridad, ambiguos y los errores compartibles) termina en la aclaración institucional, sin una sola petición HTTP,
 * sin su texto en ChatLog, en logs ni en la respuesta.
 */
class HorusPrivacidadBloqueoCompletoTest extends TestCase
{
    use RefreshDatabase;

    private const ACLARACION = 'Parece que incluiste un nombre o dato personal. Por seguridad, evita compartir información personal. Si deseas consultar sobre un curso o servicio, puedes reformular tu pregunta sin esos datos.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32)]);
    }

    /** @return list<string> */
    private function mensajesABloquear(): array
    {
        $mensajes = [];
        foreach (['desarrollo.txt', 'errores-compartibles.txt'] as $archivo) {
            foreach (file(__DIR__.'/../Fixtures/privacidad/'.$archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                $partes = explode('|', $linea, 3);
                if (count($partes) === 3 && ! str_starts_with($linea, '#') && $partes[1] === 'B') {
                    $mensajes[] = $partes[2];
                }
            }
        }

        return $mensajes;
    }

    public function test_ningun_mensaje_bloqueado_llega_a_python_ni_deja_rastro(): void
    {
        $logs = [];
        Log::listen(function ($evento) use (&$logs): void {
            $logs[] = $evento->message.' '.json_encode($evento->context, JSON_UNESCAPED_UNICODE);
        });
        $servicio = app(AsistenteVirtualService::class);
        $mensajes = $this->mensajesABloquear();
        $this->assertGreaterThan(200, count($mensajes));

        foreach ($mensajes as $i => $mensaje) {
            $resultado = $servicio->procesarMensaje($mensaje, 'bloqueo-completo-'.$i);
            $this->assertSame('respaldo', $resultado['fuente'], $mensaje);
            $this->assertSame(self::ACLARACION, $resultado['respuesta'], $mensaje);
            $this->assertNull($resultado['intent']);
        }

        Http::assertNothingSent();
        $this->assertSame(count($mensajes), ChatLog::query()->count());
        $this->assertSame(['[contenido omitido por privacidad]'], ChatLog::query()->pluck('mensaje_usuario')->unique()->values()->all());
        $this->assertSame([self::ACLARACION], ChatLog::query()->pluck('respuesta_bot')->unique()->values()->all());

        // Ningún mensaje completo ni palabra de contenido (≥6 letras) ajena a la aclaración aparece en registros ni logs.
        $almacenado = mb_strtolower(ChatLog::query()->get()->flatMap(static fn (ChatLog $r): array => array_map('strval', array_values($r->getAttributes())))->implode(' ').' '.implode(' ', $logs));
        $permitidas = array_flip(preg_split('/\P{L}+/u', mb_strtolower(self::ACLARACION.' respaldo privacidad contenido omitido'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        foreach ($mensajes as $mensaje) {
            $this->assertStringNotContainsString(mb_strtolower($mensaje), $almacenado);
            foreach (preg_split('/\P{L}+/u', mb_strtolower($mensaje), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
                if (mb_strlen($palabra) >= 6 && ! isset($permitidas[$palabra]) && preg_match('/^(?:contingencia|motivo|chatbot)$/', $palabra) !== 1) {
                    $this->assertSame(0, preg_match('/(?<!\p{L})'.preg_quote($palabra, '/').'(?!\p{L})/u', $almacenado), 'Palabra del visitante en el registro: '.$palabra);
                }
            }
        }
        $this->assertStringContainsString('privacidad', implode(' ', $logs));
    }

    public function test_la_aclaracion_no_repite_ni_resume_el_dato_detectado(): void
    {
        foreach (['soy Tamara Quilcate Dueñas', 'mi dni es 71234567', 'escríbeme a marcela.demo@example.com'] as $mensaje) {
            $respuesta = app(AsistenteVirtualService::class)->procesarMensaje($mensaje, 'aclaracion')['respuesta'];
            $this->assertSame(self::ACLARACION, $respuesta);
        }
    }
}
