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
            'servicios' => ['admin.servicios.index', '/admin/servicios/catalogo'],
            'estudiantes' => ['admin.estudiantes.index', '/admin/academico/estudiantes'],
            'cursos' => ['admin.cursos.index', '/admin/academico/cursos'],
            'matriculas' => ['admin.matriculas.index', '/admin/academico/matriculas'],
            'auditoria' => ['admin.auditoria.index', '/admin/auditoria'],
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

    public function test_la_exportacion_de_auditoria_ya_no_existe_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->post('/admin/auditoria/exportar')->assertNotFound();
    }

    public function test_las_operaciones_json_de_cotizaciones_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->postJson('/admin/cotizaciones', [])->assertStatus(405);
        $this->getJson('/admin/cotizaciones/'.$uuid)->assertNotFound();
        $this->patchJson('/admin/cotizaciones/'.$uuid.'/estado', [])->assertNotFound();
    }

    public function test_la_ficha_antigua_de_un_estudiante_redirige_a_su_ficha_en_next(): void
    {
        $this->actingAs($this->adminUser())->get(route('admin.estudiantes.show', 42))
            ->assertRedirect(self::WEB.'/admin/academico/estudiantes/42');
        $this->get('/admin/estudiantes/abc')->assertNotFound();
        $this->get('/admin/estudiantes/1;DROP')->assertNotFound();
    }

    public function test_la_ficha_antigua_de_un_curso_redirige_a_su_ficha_en_next(): void
    {
        $this->actingAs($this->adminUser())->get(route('admin.cursos.show', 7))
            ->assertRedirect(self::WEB.'/admin/academico/cursos/7');
        $this->get('/admin/cursos/abc')->assertNotFound();
        $this->get('/admin/cursos/1;DROP')->assertNotFound();
    }

    public function test_la_ficha_antigua_de_una_matricula_redirige_a_su_ficha_en_next(): void
    {
        $this->actingAs($this->adminUser())->get(route('admin.matriculas.show', 9))
            ->assertRedirect(self::WEB.'/admin/academico/matriculas/9');
        $this->get('/admin/matriculas/abc')->assertNotFound();
        $this->get('/admin/matriculas/1;DROP')->assertNotFound();
    }

    public function test_las_operaciones_de_matriculas_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->get('/admin/matriculas/create')->assertNotFound();
        $this->get('/admin/matriculas/1/edit')->assertNotFound();
        $this->postJson('/admin/matriculas', [])->assertStatus(405);
        $this->putJson('/admin/matriculas/1', [])->assertStatus(405);
        $this->deleteJson('/admin/matriculas/1')->assertStatus(405);
        $this->patchJson('/admin/matriculas/1/anular')->assertNotFound();
        $this->patchJson('/admin/matriculas/1/restaurar')->assertNotFound();
        $this->postJson('/admin/matriculas/exportar/csv')->assertNotFound();
        $this->getJson('/admin/matriculas/opciones/estudiantes?contexto=crear')->assertNotFound();
        $this->getJson('/admin/matriculas/opciones/cursos?contexto=filtro')->assertNotFound();
    }

    public function test_las_operaciones_de_cursos_y_de_su_ficha_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->get('/admin/cursos/create')->assertNotFound();
        $this->get('/admin/cursos/1/edit')->assertNotFound();
        $this->postJson('/admin/cursos', [])->assertStatus(405);
        $this->putJson('/admin/cursos/1', [])->assertStatus(405);
        $this->deleteJson('/admin/cursos/1')->assertStatus(405);
        $this->patchJson('/admin/cursos/1/restaurar')->assertNotFound();
        $this->getJson('/admin/cursos/1/publicacion')->assertNotFound();
        $this->putJson('/admin/cursos/1/publicacion', [])->assertNotFound();
        $this->deleteJson('/admin/cursos/1/publicacion')->assertNotFound();
    }

    public function test_las_operaciones_de_estudiantes_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->get('/admin/estudiantes/create')->assertNotFound();
        $this->get('/admin/estudiantes/1/edit')->assertNotFound();
        $this->postJson('/admin/estudiantes', [])->assertStatus(405);
        $this->putJson('/admin/estudiantes/1', [])->assertStatus(405);
        $this->deleteJson('/admin/estudiantes/1')->assertStatus(405);
        $this->patchJson('/admin/estudiantes/1/restaurar')->assertNotFound();
    }

    public function test_las_operaciones_del_catalogo_de_servicios_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());
        $this->postJson('/admin/servicios', [])->assertStatus(405);
        $this->getJson('/admin/servicios/1')->assertNotFound();
        $this->putJson('/admin/servicios/1', [])->assertNotFound();
        $this->patchJson('/admin/servicios/1/estado', [])->assertNotFound();
        $this->getJson('/admin/servicios/1/publicacion')->assertNotFound();
        $this->postJson('/admin/servicios/1/publicacion/autorizar', [])->assertNotFound();
        $this->postJson('/admin/servicios/1/elementos', [])->assertNotFound();
        $this->deleteJson('/admin/servicios/1/elementos/1')->assertNotFound();
    }
}
