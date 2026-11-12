<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RedirigirAWebTest extends TestCase
{
    private const WEB = 'https://web.example.test';

    /** @return array<string, array{0: string, 1: string, 2: string}> nombre de ruta, URI en Laravel, destino en Next. */
    public static function paginasRetiradas(): array
    {
        return [
            'inicio' => ['home', '/', '/'],
            'quienes somos' => ['quienes-somos', '/quienes-somos', '/quienes-somos'],
            'galeria' => ['galeria', '/galeria', '/galeria'],
            'contactos' => ['contactos', '/contactos', '/contactos'],
            'asesoramiento' => ['educacion.asesoramiento', '/educacion/asesoramiento', '/educacion/asesoramiento'],
            'capacitaciones' => ['educacion.capacitaciones', '/educacion/capacitaciones', '/educacion/capacitaciones'],
            'devoluciones' => ['legales.devolucion', '/politicas-devolucion', '/legales/politicas-devolucion'],
            'privacidad' => ['legales.privacidad', '/politicas-privacidad', '/legales/politicas-privacidad'],
            'cookies' => ['legales.cookies', '/politicas-cookies', '/legales/politicas-cookies'],
            'preguntas frecuentes' => ['legales.faq', '/preguntas-frecuentes', '/legales/preguntas-frecuentes'],
        ];
    }

    #[DataProvider('paginasRetiradas')]
    public function test_la_pagina_retirada_redirige_al_frontend_next(string $nombre, string $uri, string $destino): void
    {
        config(['horus-web.url' => self::WEB]);

        $this->assertSame($uri, '/'.ltrim(route($nombre, [], false), '/'));
        $this->get($uri)->assertStatus(302)->assertRedirect(self::WEB.$destino);
    }

    #[DataProvider('paginasRetiradas')]
    public function test_sin_url_del_frontend_responde_404_y_no_redirige(string $nombre, string $uri): void
    {
        config(['horus-web.url' => '']);

        $this->get($uri)->assertNotFound();
    }

    public function test_una_url_que_no_es_http_se_rechaza(): void
    {
        foreach (['javascript:alert(1)', '//otro.example', 'ftp://otro.example', 'web.example.test'] as $invalida) {
            config(['horus-web.url' => $invalida]);
            $this->get('/contactos')->assertNotFound();
        }
    }

    public function test_solo_se_aceptan_get_y_head(): void
    {
        config(['horus-web.url' => self::WEB]);

        $this->post('/contactos')->assertStatus(405);
        $this->head('/contactos')->assertRedirect(self::WEB.'/contactos');
    }

    public function test_las_paginas_con_datos_o_formulario_no_se_redirigen(): void
    {
        config(['horus-web.url' => self::WEB]);

        foreach (['/educacion/cursos', '/tecnologias/camaras-seguridad', '/tecnologias/cableado-estructurado', '/tecnologias/soporte-mantenimiento', '/solicitar-matricula'] as $uri) {
            $this->assertFalse($this->get($uri)->isRedirect(self::WEB.$uri), $uri);
        }
    }
}
