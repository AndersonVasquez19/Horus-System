<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Support\SenalesAsistente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Etapas de las conversaciones, comprobadas con respuestas reales del motor (fixtures). Si una frase de
 * `knowledge.json` cambia y deja de reconocerse en el historial, esta prueba falla.
 */
class SenalesAsistenteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{answer: string, intent: string}> consulta => respuesta del motor */
    private static function respuestas(): array
    {
        $leer = static fn (string $archivo): array => json_decode((string) file_get_contents(__DIR__.'/../Fixtures/'.$archivo), true, 512, JSON_THROW_ON_ERROR);
        $salida = [];
        foreach ([...$leer('chat-jurado-replies.json'), ...$leer('chat-jurado-catalogo.json')['cases']] as $caso) {
            $pregunta = $caso['reply']['needs_clarification'] ?? false ? $caso['reply']['clarification_question'] ?? '' : '';
            $salida[$caso['query']] = ['answer' => trim($caso['reply']['answer']."\n\n".$pregunta), 'intent' => $caso['reply']['intent']];
        }

        return $salida;
    }

    private static function fila(string $respuesta, ?string $tema = null): ChatLog
    {
        return new ChatLog(['respuesta_bot' => $respuesta, 'intent_detectado' => $tema]);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function etapasEsperadas(): iterable
    {
        foreach ([
            '¿Qué capacitaciones ofrecen?' => ['interesado'],
            '¿Qué modalidades tienen?' => ['interesado'],
            '¿Tienen clases virtuales?' => ['interesado'],
            '¿Puedo estudiar desde Lima?' => ['interesado'],
            '¿Cuánto cuestan los cursos?' => ['interesado'],
            '¿Cuándo empiezan los cursos?' => ['interesado'],
            '¿Cuántos cupos hay?' => ['interesado'],
            '¿Qué tipo de asesoramiento ofrecen?' => ['interesado'],
            '¿Hacen asesoría legal?' => ['interesado'],
            'oe causa qué cursos hay pe' => ['interesado'],
            'no quiero virtual' => ['interesado'],
            '¿cuáles son virtuales?' => ['interesado'],
            '¿cuál es más corto?' => ['interesado'],
            'tienen inglés?' => ['interesado'],
            'maestría?' => ['interesado'],
            '¿Cómo puedo matricularme?' => ['matricula'],
            '¿Cómo me inscribo?' => ['matricula'],
            'Quiero matricularme' => ['matricula'],
            '¿Qué requisitos piden?' => ['matricula'],
            'Quiero hablar con un asesor' => ['contacto'],
            'ya pe pásame con alguien' => ['contacto'],
            '¿Cuál es su número de teléfono?' => ['contacto'],
            '¿Tienen libro de reclamaciones?' => ['reclamos'],
            'me cobraron de más' => ['reclamos'],
            'el profesor no vino' => ['reclamos'],
            'hola' => [], 'gracias' => [], 'adiós' => [], 'cómo te llamas' => [], '¿Qué es Horus Group?' => [],
            '¿Dónde están ubicados?' => [], '¿Hacen devoluciones?' => [], '¿Aceptan Yape?' => [],
            'Cuéntame un chiste' => [], 'dame el dni de un alumno' => [], 'hello' => [], 'ok' => [],
        ] as $consulta => $etapas) {
            yield $consulta => [$consulta, $etapas];
        }
    }

    /** @param list<string> $esperadas */
    #[DataProvider('etapasEsperadas')]
    public function test_el_historial_sin_tema_se_etiqueta_por_la_respuesta(string $consulta, array $esperadas): void
    {
        $respuesta = self::respuestas()[$consulta]['answer'];

        // «Interesado» puede acompañar a una etapa más avanzada; las etiquetas de la conversación ya la resuelven.
        $this->assertSame($esperadas, SenalesAsistente::deConversacion([self::fila($respuesta)]), $respuesta);
    }

    public function test_el_filtro_de_la_base_coincide_con_las_etiquetas_en_todas_las_respuestas_reales(): void
    {
        $filas = [];
        foreach (self::respuestas() as $caso) {
            foreach ([null, $caso['intent']] as $tema) {
                $filas[] = ChatLog::create(['session_id' => 's'.count($filas), 'mensaje_usuario' => '',
                    'respuesta_bot' => $caso['answer'], 'intent_detectado' => $tema])->refresh();
            }
        }

        foreach (SenalesAsistente::ETAPAS as $etapa) {
            $esperadas = [];
            foreach ($filas as $fila) {
                $propias = SenalesAsistente::delIntercambio($fila);
                if (in_array($etapa, $propias, true) || ($etapa === 'interesado' && in_array('matricula', $propias, true))) {
                    $esperadas[] = $fila->getKey();
                }
            }
            $this->assertSame($esperadas, SenalesAsistente::filtrar(ChatLog::query(), $etapa)->orderBy('id')->pluck('id')->all(), $etapa);
        }
    }

    public function test_interesado_no_se_repite_si_la_conversacion_ya_avanzo(): void
    {
        $filas = [self::fila('x', 'training_info'), self::fila('x', 'enrollment_request'),
            self::fila('x', 'human_handoff'), (new ChatLog(['respuesta_bot' => 'x']))->forceFill(['abrio_whatsapp' => true])];

        $this->assertSame(['whatsapp', 'matricula', 'contacto'], SenalesAsistente::deConversacion($filas));
        $this->assertSame(['interesado'], SenalesAsistente::deConversacion([$filas[0]]));
    }

    public function test_una_etapa_desconocida_no_coincide_con_nada(): void
    {
        ChatLog::create(['session_id' => 's', 'mensaje_usuario' => '', 'respuesta_bot' => 'Cursos vigentes']);

        $this->assertSame(0, SenalesAsistente::filtrar(ChatLog::query(), 'otra')->count());
    }
}
