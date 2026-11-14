<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Módulos del panel retirados de Blade (Etapa F). Cada ruta antigua conserva su nombre y responde 302 al módulo
 * equivalente de apps/web; el acceso sigue exigiendo sesión y rol administrativo. Las exportaciones POST ya no existen.
 */
class AdminModulosRetiradosTest extends TestCase
{
    use RefreshDatabase;

    private const WEB = 'https://panel.horus.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['horus-web.url' => self::WEB]);
    }

    /** @return array<string, array{string, string}> */
    public static function modulos(): array
    {
        return [
            'reportes' => ['admin.reportes.index', '/admin/academico/reportes'],
            'cotizaciones' => ['admin.cotizaciones.index', '/admin/servicios/cotizaciones'],
        ];
    }

    #[DataProvider('modulos')]
    public function test_el_personal_es_redirigido_al_modulo_de_next(string $ruta, string $destino): void
    {
        $this->actingAs($this->adminUser())->get(route($ruta))->assertRedirect(self::WEB.$destino);
    }

    #[DataProvider('modulos')]
    public function test_el_acceso_sigue_exigiendo_sesion_y_rol_administrativo(string $ruta): void
    {
        $this->get(route($ruta))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route($ruta))->assertForbidden();
    }

    #[DataProvider('modulos')]
    public function test_sin_url_del_frontend_responde_404(string $ruta): void
    {
        config(['horus-web.url' => '']);
        $this->actingAs($this->adminUser())->get(route($ruta))->assertNotFound();
    }

    public function test_las_exportaciones_de_reportes_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->post('/admin/reportes/exportar/excel')->assertNotFound();
        $this->post('/admin/reportes/exportar/pdf')->assertNotFound();
    }

    public function test_las_operaciones_json_de_cotizaciones_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->postJson('/admin/cotizaciones', [])->assertStatus(405);
        $this->getJson('/admin/cotizaciones/'.$uuid)->assertNotFound();
        $this->patchJson('/admin/cotizaciones/'.$uuid.'/estado', [])->assertNotFound();
    }
}
