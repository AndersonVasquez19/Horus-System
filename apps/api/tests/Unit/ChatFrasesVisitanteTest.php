<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\TestCase;

/**
 * El filtro corre ANTES que Horus AI: una frase que el motor entiende pero el filtro bloquea nunca le llega.
 * El banco es el mismo que prueba el motor (`apps/ai/tests/test_banco_visitante.py`).
 */
class ChatFrasesVisitanteTest extends TestCase
{
    /** @return array{frases: list<array{texto: string}>, conversaciones: list<list<array{texto: string}>>, aprobadas_solo_completas: list<string>, bloqueadas_a_proposito: list<string>} */
    private function banco(): array
    {
        /** @var array{frases: list<array{texto: string}>, conversaciones: list<list<array{texto: string}>>, aprobadas_solo_completas: list<string>, bloqueadas_a_proposito: list<string>} $banco */
        $banco = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/chat-frases-visitante.json'), true, 512, JSON_THROW_ON_ERROR);

        return $banco;
    }

    public function test_ninguna_frase_ni_conversacion_del_banco_se_bloquea(): void
    {
        $filtro = new FiltroPrivacidadChat;
        $bloqueadas = [];
        foreach ([...$this->banco()['frases'], ...array_merge(...$this->banco()['conversaciones'])] as $caso) {
            if (($motivo = $filtro->motivoBloqueo($caso['texto'])) !== null) {
                $bloqueadas[] = $caso['texto'].' ['.$motivo.']';
            }
        }
        $this->assertSame([], $bloqueadas);
    }

    /** La excepción vale solo para el mensaje completo: con cualquier cosa al lado deciden las reglas de siempre. */
    public function test_una_frase_aprobada_no_exime_lo_que_la_acompana(): void
    {
        $filtro = new FiltroPrivacidadChat;
        $permitidas = [];
        foreach ([...$this->banco()['aprobadas_solo_completas'], 'holaaaa', 'holiii', 'chauu'] as $frase) {
            foreach ([' Quilcate Huaman', ' soy anderson vela', ', María Pérez', ' 987654321', ' ana@correo.pe', ' DNI 00000000', ' 张伟 王芳'] as $extra) {
                if ($filtro->motivoBloqueo($frase.$extra) === null) {
                    $permitidas[] = $frase.$extra;
                }
            }
        }
        $this->assertSame([], $permitidas);
    }

    /** Escritas solas podrían ser un nombre: ante la duda se bloquea. */
    public function test_lo_dudoso_sigue_bloqueado(): void
    {
        $filtro = new FiltroPrivacidadChat;
        foreach ([...$this->banco()['bloqueadas_a_proposito'], 'Quilcate', 'Holaya', 'Chau Quilcate', 'wenasco'] as $mensaje) {
            $this->assertNotNull($filtro->motivoBloqueo($mensaje), $mensaje);
        }
    }
}
