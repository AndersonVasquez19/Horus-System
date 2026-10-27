<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_homepage_renders_content_without_a_blocking_loader(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('carousel-frame', false)
            ->assertDontSee('class="loading-shell"', false);
    }

    public function test_page_styles_and_secondary_images_are_loaded_by_section(): void
    {
        $this->get('/tecnologias/camaras-seguridad')
            ->assertOk()
            ->assertSee('assets/css/tecnologias/camaras-seguridad.css', false)
            ->assertDontSee('assets/css/educacion/cursos.css', false);

        $this->get('/tecnologias/cableado-estructurado')
            ->assertOk()
            ->assertSee('alt="Categoría 1" loading="lazy" decoding="async"', false);
    }
}
