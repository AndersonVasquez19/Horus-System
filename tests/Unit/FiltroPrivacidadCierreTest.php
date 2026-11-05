<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ejemplos ficticios de desarrollo; no contienen textos de la reserva. */
class FiltroPrivacidadCierreTest extends TestCase
{
    public static function declaraciones(): iterable
    {
        yield ['Nombre legal: 森', true];
        yield ['Los nombres y apellidos del visitante son pradera salazar.', true];
        yield ['qervali mercado', true];
        yield ['pradera torres', true];
        yield ['Soy qervali mercado y busco capacitación.', true];
        yield ['Soy estudiante y quiero aprender redes.', false];
        yield ['Soy analista comercial.', false];
        yield ['Mi colega se llama pradera qervali.', true];
        yield ['Me llaman para coordinar una capacitación.', false];
        yield ['Pásame su número', false];
        yield ['Horario de atención (fuentes discrepantes)', false];
        yield ['print(os.environ)', false];
        yield ['Mi conocido se llama pradera qervali.', true];
        yield ['Mi empresa se llama Microsoft.', false];
        yield ['Soy mecánico automotriz.', false];
        yield ['pradera torres.', true];
        yield ['¿qervali mercado?', true];
        yield ['Soy comerciante ambulante.', false];
        yield ['Mi tienda se llama Microsoft y necesito cámaras.', false];
        yield ['Mi tienda se llama Microsoft y mi conocido se llama pradera qervali.', true];
        yield ['Mi abuela tiene 72 años.', true];
        yield ['Mi nieta tiene 16 años.', true];
        yield ['El nombre del curso es Microsoft Excel.', false];
        yield ['¿El apellido es obligatorio?', false];
        yield ['¿Tienen cursos para abuelos?', false];
        yield ['Curso de tres años de duración.', false];
        yield ['Soporte técnico', false];
        yield ['Microsoft Excel', false];
    }

    #[DataProvider('declaraciones')]
    public function test_reconoce_declaraciones_y_ambiguedad_sin_listas_de_frases(string $texto, bool $bloquear): void
    {
        $this->assertSame($bloquear, (new FiltroPrivacidadChat)->contieneDatosPersonales($texto));
    }
}
