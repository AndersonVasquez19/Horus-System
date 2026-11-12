<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_redirects_to_the_next_frontend(): void
    {
        config(['horus-web.url' => 'https://web.example.test']);

        $this->get('/')->assertRedirect('https://web.example.test/');
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
