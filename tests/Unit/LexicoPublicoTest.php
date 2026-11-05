<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Privacidad\LexicoPublico;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** El léxico público explica vocabulario común y no debe «explicar» nombres de personas. */
class LexicoPublicoTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function palabrasComunes(): iterable
    {
        foreach (['curso', 'cursos', 'matricularme', 'ofimatica', 'camara', 'camaras', 'cotizacion', 'instalar', 'precio', 'horario',
            'excel', 'cajamarca', 'certificado', 'nuevas', 'rapidamente', 'pastelero', 'pastelera', 'fotografo', 'policia',
            'mecanico', 'estudiantes', 'docentes', 'ciudades'] as $p) {
            yield $p => [$p];
        }
    }

    #[DataProvider('palabrasComunes')]
    public function test_reconoce_vocabulario_comun_y_sus_variantes(string $palabra): void
    {
        $this->assertTrue(LexicoPublico::contiene($palabra), $palabra);
    }

    /**
     * Control independiente del filtro: nombres y apellidos ficticios o frecuentes que el léxico NO debe contener.
     *
     * @return iterable<string, array{string}>
     */
    public static function nombresQueNoDebenEstar(): iterable
    {
        foreach (['dario', 'anderson', 'walter', 'yuli', 'brayan', 'kevin', 'brenda', 'carlos', 'maria', 'jhonatan', 'tamara', 'noelia',
            'ariel', 'ismael', 'joaquin', 'hernan', 'abigail', 'marlith', 'eudes', 'yamileth', 'rodrigo', 'kenji', 'lourdes', 'ovidio',
            'dalila', 'gerson', 'thiago', 'liseth', 'wilfredo', 'ruth', 'clementina', 'edinson', 'bertha', 'fabrizio', 'nataly',
            'quispe', 'mamani', 'huaman', 'chilcon', 'tantalean', 'sangay', 'cuzco', 'tello', 'huatay', 'cieza', 'quinchua', 'terrones',
            'mendoza', 'vasquez', 'sanchez', 'rodriguez', 'carranza', 'cubas', 'zelada', 'minchan', 'bardales', 'pisfil'] as $n) {
            yield $n => [$n];
        }
    }

    #[DataProvider('nombresQueNoDebenEstar')]
    public function test_no_explica_nombres_de_personas(string $nombre): void
    {
        $this->assertFalse(LexicoPublico::contiene($nombre), $nombre);
    }

    public function test_una_forma_verbal_no_hace_conocido_a_un_nombre_por_cambio_de_genero(): void
    {
        $this->assertTrue(LexicoPublico::contiene('daria'));
        $this->assertFalse(LexicoPublico::contiene('dario'));
    }

    public function test_cargado_una_vez_y_con_tamano_razonable(): void
    {
        $this->assertGreaterThan(5000, LexicoPublico::total());
        $this->assertLessThan(100000, LexicoPublico::total());
    }
}
