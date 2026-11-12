<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicContentTest extends TestCase
{
    public function test_camera_cards_include_one_image_each(): void
    {
        $response = $this->get('/tecnologias/camaras-seguridad')->assertOk();

        $this->assertSame(10, substr_count($response->getContent(), 'class="carta-imagen carta-imagen--'));

        foreach (['camara-ip', 'camara-analogica', 'camara-full-hd', 'camara-domo', 'camara-360', 'camara-tubo-bala', 'sensor-movimiento', 'alarma-email', 'vision-nocturna', 'visualizacion-dispositivos'] as $imagen) {
            $response->assertSee("assets/images/videovigilancia/{$imagen}.jpg", false);
            $this->assertFileExists(public_path("assets/images/videovigilancia/{$imagen}.jpg"));
        }
    }

    public function test_market_route_no_longer_exists(): void
    {
        $this->get('/market')->assertNotFound();
    }
}
