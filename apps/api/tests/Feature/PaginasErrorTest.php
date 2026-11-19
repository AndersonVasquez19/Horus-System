<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Pulido del panel (C): páginas de error propias en español, autónomas (sin Vite, scripts ni base de datos) y
 * sin detalles técnicos. Las respuestas JSON no cambian. SQLite en memoria.
 */
class PaginasErrorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'detalle-interno-que-no-debe-verse-7731';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['app.debug' => false]);

        Route::get('/_prueba/abortar/{codigo}', fn (string $codigo) => abort((int) $codigo, self::SECRETO))
            ->whereNumber('codigo');
        Route::get('/_prueba/fallo', fn () => throw new RuntimeException(self::SECRETO));
    }

    public function test_un_404_publico_es_en_espanol_y_autonomo(): void
    {
        $html = (string) $this->get('/ruta-que-no-existe-en-horus')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertSee('Ir a la página principal')
            ->assertDontSee('Not Found')
            ->getContent();

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('/build/', $html);
    }

    public function test_el_boton_de_las_paginas_de_error_apunta_a_la_interfaz_next(): void
    {
        config(['horus-web.url' => 'https://web.example.test']);

        $this->get('/ruta-que-no-existe-en-horus')->assertNotFound()
            ->assertSee('href="https://web.example.test/"', false);

        Route::middleware('web')->get('/admin/prueba-403-destino', fn () => abort(403));
        $this->get('/admin/prueba-403-destino')->assertForbidden()
            ->assertSee('href="https://web.example.test/admin"', false)->assertSee('Volver al panel');

        config(['horus-web.url' => '']);
        $this->get('/ruta-que-no-existe-en-horus')->assertNotFound()->assertSee('href="/"', false);
    }

    public function test_un_403_del_panel_es_en_espanol_y_vuelve_al_panel(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $secretaria = User::factory()->create();
        $secretaria->assignRole('secretaria');

        \Illuminate\Support\Facades\Route::middleware('web')->get('/admin/prueba-403', fn () => abort(403));

        $this->actingAs($secretaria)->get('/admin/prueba-403')
            ->assertForbidden()
            ->assertSee('Acceso no permitido')
            ->assertSee('Volver al panel')
            ->assertDontSee('Forbidden')
            ->assertDontSee('does not have the right permissions');
    }

    public function test_419_429_y_503_tienen_su_pagina_sin_el_mensaje_tecnico(): void
    {
        foreach ([419 => 'La sesión expiró', 429 => 'Demasiadas solicitudes', 503 => 'Sitio en mantenimiento'] as $codigo => $titulo) {
            $this->get('/_prueba/abortar/'.$codigo)
                ->assertStatus($codigo)
                ->assertSee($titulo)
                ->assertDontSee(self::SECRETO);
        }
    }

    public function test_un_500_no_expone_la_excepcion(): void
    {
        $this->get('/_prueba/fallo')
            ->assertStatus(500)
            ->assertSee('Ocurrió un error')
            ->assertDontSee(self::SECRETO)
            ->assertDontSee('RuntimeException');
    }

    public function test_las_respuestas_json_siguen_siendo_json(): void
    {
        $this->getJson('/ruta-que-no-existe-en-horus')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
