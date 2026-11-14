<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ImagenesServicios;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LlamaServiciosDelPanel;
use Tests\TestCase;

/**
 * Fase 2a: servicios y páginas de tecnologías en `/api/public/v1` con datos reales (fichas publicadas, no publicadas
 * y retiradas) y con imagen. El panel prepara los datos; el BFF solo lee con el token de servicio.
 */
class ApiPublicaServiciosTest extends TestCase
{
    use LlamaServiciosDelPanel;
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-servicios.enabled' => true]);
        Http::preventStrayRequests();
        Storage::fake(ImagenesServicios::DISCO);
    }

    /** @return array<string, string> */
    private function bff(): array
    {
        return ['X-Horus-Web-Token' => self::TOKEN];
    }

    private function etag(int $id): string
    {
        return $this->revisionServicioPanel($id);
    }

    /** Servicio con ficha en borrador (sin activar ni autorizar). */
    private function servicio(string $codigo, string $titulo): int
    {
        $id = $this->crearServicioPanel(['codigo' => $codigo, 'nombre' => 'Servicio '.$codigo, 'categoria' => 'Redes']);
        $this->guardarFichaPanel($id, [
            'titulo' => $titulo, 'resumen' => 'Resumen público', 'descripcion' => "Primer párrafo.\n\nSegundo párrafo.",
            'vigente_desde' => '2026-11-01', 'vigente_hasta' => '2026-12-31', 'titulo_tarjetas' => 'Tarjetas del servicio',
        ])->assertOk();

        return $id;
    }

    private function publicar(int $id): void
    {
        $this->cambiarEstadoServicioPanel($id, 'activo')->assertOk();
        $this->autorizarFichaPanel($id)->assertOk();
    }

    public function test_ficha_sin_autorizar_no_aparece_y_publicada_si_y_retirada_desaparece(): void
    {
        $this->actingAs($this->adminUser());
        $id = $this->servicio('CABLEADO', 'Cableado publicado');
        $generico = $this->servicio('GENERICO', 'Servicio genérico');

        $this->getJson('/api/public/v1/servicios', $this->bff())->assertOk()
            ->assertJsonPath('habilitado', true)->assertJsonPath('total', 0)->assertJsonPath('servicios', []);
        $this->getJson('/api/public/v1/paginas/cableado-estructurado', $this->bff())->assertOk()->assertJsonPath('contenido', null);

        $this->publicar($id);
        $this->publicar($generico);
        // El catálogo genérico no repite los servicios que ya tienen página propia (CABLEADO, VIDEOVIGILANCIA, SOPORTE).
        $catalogo = $this->getJson('/api/public/v1/servicios', $this->bff())->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('por_pagina', 12)->assertJsonPath('servicios.0.titulo', 'Servicio genérico');
        $catalogo->assertDontSee('autorizado')->assertDontSee('"id"')->assertDontSee('Cableado publicado');

        $this->getJson('/api/public/v1/paginas/cableado-estructurado', $this->bff())->assertOk()
            ->assertJsonPath('pagina', 'cableado-estructurado')->assertJsonPath('contenido.titulo', 'Cableado publicado')
            ->assertJsonPath('contenido.parrafos', ['Primer párrafo.', 'Segundo párrafo.'])
            ->assertJsonPath('contenido.titulo_tarjetas', 'Tarjetas del servicio');
        // Otra página sin ficha sigue sin contenido.
        $this->getJson('/api/public/v1/paginas/camaras-seguridad', $this->bff())->assertOk()->assertJsonPath('contenido', null);

        $this->retirarFichaPanel($id)->assertOk();
        $this->retirarFichaPanel($generico)->assertOk();
        $this->getJson('/api/public/v1/servicios', $this->bff())->assertJsonPath('total', 0);
        $this->getJson('/api/public/v1/paginas/cableado-estructurado', $this->bff())->assertJsonPath('contenido', null);
    }

    public function test_las_imagenes_llegan_como_url_de_storage_y_solo_con_ruta_valida(): void
    {
        $this->actingAs($this->adminUser());
        $id = $this->servicio('VIDEOVIGILANCIA', 'Videovigilancia con imagen');
        $this->panelServiciosArchivo("$id/elementos", [
            'titulo' => 'Tarjeta con imagen', 'descripcion' => 'Descripción', 'imagen' => UploadedFile::fake()->image('foto.png', 200, 150),
        ], ['If-Match' => $this->etag($id)])->assertCreated();
        // El panel nunca publica rutas de almacenamiento: se lee la ruta guardada directamente de la base.
        $ruta = (string) DB::table('servicio_publicacion_elementos')->value('imagen_ruta');
        $this->publicar($id);

        $contenido = $this->getJson('/api/public/v1/paginas/camaras-seguridad', $this->bff())->assertOk()
            ->assertJsonPath('contenido.tarjetas.0.titulo', 'Tarjeta con imagen')->json('contenido');
        $this->assertSame('', $contenido['tarjetas'][0]['imagen_alt']);
        $this->assertStringEndsWith('/storage/'.$ruta, (string) $contenido['tarjetas'][0]['imagen_url']);
        $this->assertMatchesRegularExpression('#\A/storage/servicios/[0-9a-f-]{36}\.png\z#', (string) parse_url((string) $contenido['tarjetas'][0]['imagen_url'], PHP_URL_PATH));
        $this->assertNull($contenido['imagen_url']);

        // Una ruta fuera del formato permitido nunca se convierte en URL.
        $this->assertNull(ImagenesServicios::url('../../.env'));
        $this->assertNull(ImagenesServicios::url('https://evil.example/a.png'));
    }

    public function test_el_catalogo_pagina_de_12_en_12_y_sin_token_no_responde(): void
    {
        $this->actingAs($this->adminUser());
        foreach (range(1, 13) as $n) {
            $this->publicar($this->servicio('SERV'.$n, "Servicio $n"));
        }

        $this->getJson('/api/public/v1/servicios?pagina=1', $this->bff())->assertOk()->assertJsonPath('total', 13)->assertJsonCount(12, 'servicios');
        $this->getJson('/api/public/v1/servicios?pagina=2', $this->bff())->assertOk()->assertJsonPath('pagina', 2)->assertJsonCount(1, 'servicios');
        $this->getJson('/api/public/v1/servicios?pagina=3', $this->bff())->assertOk()->assertJsonPath('servicios', []);

        $this->getJson('/api/public/v1/servicios')->assertUnauthorized();
        $this->getJson('/api/public/v1/paginas/soporte-mantenimiento')->assertUnauthorized();
    }
}
