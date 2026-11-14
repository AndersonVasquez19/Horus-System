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
            'reclamaciones' => ['admin.reclamaciones.index', '/admin/reclamaciones'],
            'perfil' => ['admin.perfil.edit', '/admin/perfil'],
            'notificaciones' => ['admin.notificaciones.index', '/admin/notificaciones'],
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

    public function test_el_expediente_y_la_hoja_antiguos_redirigen_a_next_y_la_gestion_ya_no_existe(): void
    {
        $uuid = '6f1b7f9e-2c1a-4b8e-9d1f-1a2b3c4d5e6f';
        $this->actingAs($this->adminUser());

        $this->get(route('admin.reclamaciones.show', $uuid))->assertRedirect(self::WEB.'/admin/reclamaciones/'.$uuid);
        $this->get(route('admin.reclamaciones.hoja', $uuid))->assertRedirect(self::WEB.'/admin/reclamaciones/'.$uuid.'/hoja');
        $this->get('/admin/reclamaciones/no-es-uuid')->assertNotFound();
        $this->post('/admin/reclamaciones/'.$uuid.'/gestion', ['operacion' => 'nota', 'nota' => 'x'])->assertNotFound();
    }

    public function test_la_edicion_del_perfil_y_las_sesiones_ya_no_existen_en_blade(): void
    {
        $this->actingAs($this->adminUser());

        $this->put('/admin/perfil', ['name' => 'X', 'email' => 'x@example.invalid'])->assertStatus(405);
        $this->getJson('/admin/perfil/sesiones')->assertNotFound();
        $this->deleteJson('/admin/perfil/sesiones', ['current_password' => 'x'])->assertNotFound();
        $this->deleteJson('/admin/perfil/sesiones/'.str_repeat('a', 32), ['current_password' => 'x'])->assertNotFound();
    }

    public function test_marcar_una_notificacion_ya_no_existe_en_blade_pero_la_campana_sigue(): void
    {
        $this->actingAs($this->adminUser());

        $this->patchJson('/admin/notificaciones/1/leida')->assertNotFound();
        // La campana del shell Blade (hasta la Ola 4) conserva sus dos endpoints JSON.
        $this->getJson(route('admin.notificaciones.recientes'))->assertOk()->assertJsonStructure(['pendientes', 'notificaciones']);
        $this->patchJson(route('admin.notificaciones.leidas'))->assertOk()->assertJsonStructure(['marcadas', 'pendientes']);
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
