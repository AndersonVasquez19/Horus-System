<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicContentTest extends TestCase
{
    public function test_home_has_seo_metadata_and_a_single_h1(): void
    {
        $response = $this->get('/')->assertOk();

        $response
            ->assertSee('<title>Horus Group SRL | Tecnología, capacitaciones y soporte en Cajamarca</title>', false)
            ->assertSee('<meta name="description" content="Horus Group SRL: cableado estructurado', false)
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('application/ld+json', false);

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_home_hero_renders_every_slide_with_its_overlay(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertSame(4, substr_count($response->getContent(), 'class="hero-backdrop '));
        $response->assertSee('hero-overlay', false)->assertSee('carousel(4)', false);
    }

    public function test_gallery_has_no_video_filter(): void
    {
        $this->get('/galeria')
            ->assertOk()
            ->assertDontSee('VÍDEOS', false)
            ->assertDontSee('type-filter', false)
            ->assertDontSee('fa-video', false);
    }

    public function test_team_section_no_longer_lists_humberto_camacho(): void
    {
        $this->get('/quienes-somos')
            ->assertOk()
            ->assertDontSee('Humberto', false)
            ->assertSee('KAREN TÁVARA', false)
            ->assertSee('MILAGROS VILLEGAS', false);
    }

    public function test_advisory_cards_include_their_images(): void
    {
        $this->get('/educacion/asesoramiento')
            ->assertOk()
            ->assertSee('assets/images/asesoramiento/asesoramiento-educativo.jpg', false)
            ->assertSee('assets/images/asesoramiento/asesoramiento-empresarial.jpg', false)
            ->assertSee('assets/images/asesoramiento/asesoramiento-legal.jpg', false);
    }

    public function test_camera_cards_include_one_image_each(): void
    {
        $response = $this->get('/tecnologias/camaras-seguridad')->assertOk();

        $this->assertSame(10, substr_count($response->getContent(), 'class="carta-imagen carta-imagen--'));

        foreach (['camara-ip', 'camara-analogica', 'camara-full-hd', 'camara-domo', 'camara-360', 'camara-tubo-bala', 'sensor-movimiento', 'alarma-email', 'vision-nocturna', 'visualizacion-dispositivos'] as $imagen) {
            $response->assertSee("assets/images/videovigilancia/{$imagen}.jpg", false);
            $this->assertFileExists(public_path("assets/images/videovigilancia/{$imagen}.jpg"));
        }
    }

    public function test_home_lists_exactly_the_five_current_convenios(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertSame(5, substr_count($response->getContent(), 'class="convenio-card"'));

        foreach (['18 de julio del 2027', '17 de febrero del 2027', '6 de febrero del 2027', '6 de enero del 2027', '4 de junio del 2029'] as $vigencia) {
            $response->assertSee($vigencia, false);
        }

        $response->assertSee('CR II La Libertad', false)
            ->assertDontSee('Obstetras', false)
            ->assertDontSee('Ilustre Colegio de Abogados', false);
    }

    public function test_training_cards_include_their_images(): void
    {
        $response = $this->get('/educacion/capacitaciones')->assertOk();

        foreach (['capacitaciones-empresariales', 'formacion-personalizada', 'certificaciones-profesionales', 'convenios-institucionales'] as $imagen) {
            $response->assertSee("assets/images/capacitaciones/{$imagen}.jpg", false);
            $this->assertFileExists(public_path("assets/images/capacitaciones/{$imagen}.jpg"));
        }
    }

    public function test_market_route_no_longer_exists(): void
    {
        $this->get('/market')->assertNotFound();
    }
}
