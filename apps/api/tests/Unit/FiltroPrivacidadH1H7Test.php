<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Hallazgos H-1 a H-6 de la auditoría independiente de privacidad (casos de desarrollo, no de la reserva). */
class FiltroPrivacidadH1H7Test extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nombresQueDebenBloquearse(): iterable
    {
        foreach ([
            // H-1: apellidos y nombres con terminaciones antes descartadas.
            'soy anderson hurtado', 'soy kelly maldonado', 'soy brayan coronado', 'soy yuli estrada', 'soy jhon mercado', 'soy anderson bautista',
            'soy rolando tasilla', 'soy orlando chilon', 'soy concepción tafur', 'matricula a kelly maldonado', 'inscribe a jhonatan mercado',
            'ayelen bautista necesita soporte', 'soy nayeli quezada', 'soy kelly estrada hurtado',
            // H-2: títulos, variantes de «soy», apodos y terceros.
            'soy el ingeniero walter sangay', 'soy la licenciada kelly tello', 'soy el profesor jhonatan sangay', 'soy el técnico nayeli tafur',
            'zoy kelly quiroz', 'soyy jhonatan sangay', 'sooy ayelen tasilla', 'me dicen yoselin cabanillas', 'me dicen jhonny, jhonny rabanal',
            'mi colega rubí llanos quiere el curso', 'inscriban a la niña deysi huatay',
            // H-3: el mismo contexto de un falso positivo no exime un nombre completo.
            'soy el mecánico walter sangay', 'soy psicólogo, anderson hurtado', 'soy guía turístico anderson vela',
            'mi alumna favorita yuli huatay aprendió excel', 'soy asesor comercial kelly maldonado',
        ] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('nombresQueDebenBloquearse')]
    public function test_bloquea_nombres_con_contexto(string $q): void
    {
        $this->assertSame('nombre_personal', (new FiltroPrivacidadChat)->motivoBloqueo($q), $q);
    }

    /** @return iterable<string, array{string}> */
    public static function consultasLegitimas(): iterable
    {
        foreach ([
            // H-3: oficios y adjetivos.
            'soy mecánico automotriz', 'soy psicólogo clínico', 'soy guía turístico', 'soy asesor comercial', 'soy zurdo, ¿importa?',
            'mi alumna favorita aprendió excel', 'soy veterinaria rural', 'soy comerciante ambulante', 'soy cocinero profesional',
            'soy socio fundador', 'soy médico cirujano',
            // H-4: RUC institucional.
            '¿Cuál es su RUC?', '¿cuál es su ruc?', '¿me dan su RUC para la factura?', '¿su ruc es de persona jurídica?',
            // H-5 y H-6.
            'soporte para tablets samsung', 'atención para comunidades campesinas', 'pantalla táctil necesita reparación', 'registrar mi asistencia al taller',
        ] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('consultasLegitimas')]
    public function test_permite_consultas_legitimas(string $q): void
    {
        $this->assertNull((new FiltroPrivacidadChat)->motivoBloqueo($q), $q);
    }

    /** @return iterable<string, array{string, string}> */
    public static function identificacionPersonal(): iterable
    {
        yield 'ruc personal con posesivo' => ['mi ruc es 10458796321', 'documento'];
        yield 'ruc personal sin posesivo' => ['ruc 10458796321 para la factura', 'documento'];
        yield 'pregunta con dígitos' => ['¿mi ruc 10458796321 está activo?', 'documento'];
        yield 'pregunta con posesivo propio' => ['¿mi DNI es el que les pasé?', 'documento'];
        yield 'afirmación con posesivo' => ['su DNI es el de la ficha', 'documento'];
        yield 'dni del alumno' => ['¿el dni del alumno sirve?', 'documento'];
        yield 'mi asistencia como expediente' => ['quiero ver mi asistencia y mis notas', 'ambiguo'];
    }

    #[DataProvider('identificacionPersonal')]
    public function test_mantiene_bloqueada_la_identificacion_personal(string $q, string $motivo): void
    {
        $this->assertSame($motivo, (new FiltroPrivacidadChat)->motivoBloqueo($q), $q);
    }
}
