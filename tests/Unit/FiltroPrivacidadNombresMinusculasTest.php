<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Nombres completos escritos en minúsculas: detección contextual sin depender de mayúsculas. */
class FiltroPrivacidadNombresMinusculasTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fallosOriginales(): iterable
    {
        foreach (['soy anderson vela', 'soy anderson vela y quiero matricularme', 'matricula a yuli huatay cieza en excel',
            'brayan cuzco tello necesita soporte'] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('fallosOriginales')]
    public function test_bloquea_los_cuatro_fallos_originales(string $q): void
    {
        $this->assertSame('nombre_personal', (new FiltroPrivacidadChat)->motivoBloqueo($q));
    }

    /** @return iterable<string, array{string}> */
    public static function yaBloqueados(): iterable
    {
        foreach (['quiero inscribir a kelly sánchez rojas', 'mi nombre es anderson vela', 'me llamo yuli huatay',
            'soy carlos mendoza', 'inscribe a maria fernanda lopez'] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('yaBloqueados')]
    public function test_los_cinco_ya_bloqueados_siguen_bloqueados(string $q): void
    {
        $this->assertTrue((new FiltroPrivacidadChat)->contieneDatosPersonales($q));
    }

    /** @return iterable<string, array{string}> */
    public static function variantes(): iterable
    {
        foreach ([
            // Presentación personal con errores ortográficos, tildes, Unicode y espacios variables.
            'soi anderson vela', 'Soy   anderson    vela', "soy\u{00A0}anderson\u{2003}vela", 'soy ánderson véla',
            "soy ande\u{0301}rson vela", 'hola, soy anderson vela', 'buenas tardes soy anderson vela, quiero excel',
            'me presento: anderson vela', 'les saluda brayan cuzco tello', 'soy yuli huatay-cieza',
            // Matrícula o inscripción de otra persona.
            'matrícula a yuli huatay cieza en excel', 'matricular a yuli huatay', 'matricúlame a brayan cuzco',
            'inscribe al alumno brayan cuzco tello', 'quiero registrar a kelly huatay', 'inscripción de yuli huatay cieza',
            'matricula a mi hijo brayan cuzco', 'quiero matricular a la señorita yuli huatay',
            // Atención o soporte para una persona identificada.
            'brayan cuzco tello necesita soporte', 'brayan cuzco quiere matricularse', 'yuli huatay cieza desea el curso de excel',
            'soporte para brayan cuzco tello', 'atención a nombre de yuli huatay', 'mi hijo brayan cuzco tello quiere excel',
            'el alumno yuli huatay pide su certificado', 'mi sobrina yuli huatay necesita ofimática',
            // Nombres compuestos.
            'soy maría de los ángeles huatay', 'soy juan josé vela tello', 'inscribe a ana lucía cuzco',
        ] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('variantes')]
    public function test_bloquea_variantes_contextuales(string $q): void
    {
        $this->assertTrue((new FiltroPrivacidadChat)->contieneDatosPersonales($q), $q);
    }

    /** @return iterable<string, array{string}> */
    public static function legitimas(): iterable
    {
        foreach ([
            '¿Tienen cursos de Microsoft Excel?', 'Curso de Power BI.', 'Ofimática Básica.', '¿Tienen cursos para estudiantes?',
            '¿Hacen cableado en San Marcos?', '¿Dónde están ubicados?', '¿Aceptan pago con BCP?', '¿Qué convenios tienen?',
            '¿Ofrecen Wi-Fi?', '¿Cómo puedo matricularme?',
            // Contextos de riesgo con contenido institucional.
            'soy estudiante universitario', 'soy nuevo en excel', 'soy de cajamarca', 'soy principiante en power bi',
            'soy docente y quiero capacitar a mis alumnos', 'soy dueño de una empresa pequeña', 'soy egresado reciente',
            'quiero matricularme en ofimática básica', 'matricula en excel avanzado', 'inscribirme al curso de python',
            'quiero inscribir a mi hijo en excel', 'matricular a mis trabajadores en power bi', 'inscripción de alumnos del colegio',
            'registro de asistencia', 'la impresora necesita mantenimiento', 'mi laptop lenovo necesita soporte',
            'nuestra empresa necesita cámaras de seguridad', 'soporte para mi oficina', 'soporte para computadoras de escritorio',
            'mantenimiento para la red wifi', 'el colegio de enfermeros tiene convenio', 'el instituto isam tiene convenio',
            'mi hijo quiere aprender programación', 'cableado estructurado necesita certificación',
            'necesito soporte técnico urgente', 'soporte técnico a domicilio', 'quiero matricularme mañana',
            '¿Tienen convenio con el Colegio de Economistas?', '¿Trabajan con el Colegio de Licenciados en Administración?',
        ] as $q) {
            yield $q => [$q];
        }
    }

    #[DataProvider('legitimas')]
    public function test_mantiene_permitidas_las_consultas_legitimas(string $q): void
    {
        $this->assertNull((new FiltroPrivacidadChat)->motivoBloqueo($q), $q);
    }

    /** @return iterable<string, array{string}> */
    public static function consultasDelConocimiento(): iterable
    {
        $kb = json_decode((string) file_get_contents(__DIR__.'/../../chatbot-python/data/knowledge.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($kb['records'] as $record) {
            foreach (array_merge([$record['title']], $record['examples'] ?? []) as $q) {
                if (is_string($q)) {
                    yield $record['id'].': '.$q => [$q];
                }
            }
        }
    }

    /** Instituciones, empresas y servicios publicados (títulos y preguntas de ejemplo). */
    #[DataProvider('consultasDelConocimiento')]
    public function test_no_bloquea_titulos_ni_ejemplos_del_conocimiento_publicado(string $q): void
    {
        $this->assertFalse((new FiltroPrivacidadChat)->contieneDatosPersonales($q), $q);
    }
}
