<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelServiciosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    public function test_panel_html_y_json_conservan_contrato_y_estado_vacio(): void
    {
        $this->actingAs($this->adminUser());
        $this->get('/admin/servicios')->assertOk()->assertViewIs('admin.servicios.index')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Servicios tecnológicos')->assertSee('No hay servicios')->assertSee('data-panel-servicios', false)
            ->assertSee('name="_token"', false)->assertSee('Nuevo servicio');
        $this->getJson('/admin/servicios')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
    }

    public function test_lector_solo_ve_acciones_autorizadas_y_navegacion(): void
    {
        $this->adminUser();
        $lector = User::factory()->create();
        $lector->assignRole('secretaria');
        $this->actingAs($lector)->get('/admin')->assertOk()->assertDontSee('title="Servicios tecnológicos"', false);
        $this->get('/admin/servicios')->assertForbidden();
        $lector->givePermissionTo('servicios.ver');
        $this->get('/admin/servicios')->assertOk()->assertSee('title="Servicios tecnológicos"', false)
            ->assertDontSee('data-accion="nuevo"', false)->assertDontSee('data-form="administrativo"', false)
            ->assertDontSee('data-form="editorial"', false)->assertDontSee('data-form="autorizar"', false)
            ->assertDontSee('data-accion="retirar"', false);
        $this->getJson('/admin/servicios')->assertOk();
    }

    public function test_editor_y_publicador_son_independientes_en_html(): void
    {
        $this->adminUser();
        $usuario = User::factory()->create();
        $usuario->assignRole('coordinador');
        $usuario->givePermissionTo(['servicios.ver', 'servicios.editar']);
        $this->actingAs($usuario)->get('/admin/servicios')->assertOk()->assertSee('data-form="editorial"', false)
            ->assertDontSee('data-form="autorizar"', false)->assertDontSee('data-accion="nuevo"', false);
        $usuario->revokePermissionTo('servicios.editar');
        $usuario->givePermissionTo('servicios.publicar');
        $this->get('/admin/servicios')->assertOk()->assertSee('data-form="autorizar"', false)
            ->assertDontSee('data-form="editorial"', false);
    }

    public function test_invitado_y_permiso_sin_rol_no_acceden(): void
    {
        $this->get('/admin/servicios')->assertRedirect(route('login'));
        $this->adminUser();
        $externo = User::factory()->create();
        $externo->givePermissionTo('servicios.ver');
        $this->actingAs($externo)->get('/admin/servicios')->assertForbidden();
    }

    public function test_listado_filtrado_paginado_editorial_y_texto_escapado_sin_n_mas_uno(): void
    {
        $this->actingAs($this->adminUser());
        for ($i = 1; $i <= 17; $i++) {
            $id = DB::table('servicios_tecnologicos')->insertGetId([
                'identificador_publico' => (string) Str::uuid(), 'codigo' => 'TEC-'.$i,
                'nombre' => $i === 1 ? '<script>intruso</script>' : 'Servicio ficticio '.$i,
                'estado' => $i === 17 ? 'inactivo' : 'activo', 'revision' => 1,
            ]);
            DB::table('servicio_publicaciones')->insert([
                'servicio_tecnologico_id' => $id, 'titulo' => 'Ficha ficticia', 'resumen' => 'Resumen', 'descripcion' => 'Detalle',
                'estado' => 'borrador', 'vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-11-30',
            ]);
        }
        $this->get('/admin/servicios?estado=activo')->assertOk();
        DB::enableQueryLog();
        $this->get('/admin/servicios?estado=activo')->assertOk()->assertSee('16 coincidencias')->assertSee('Borrador')
            ->assertSee('&lt;script&gt;intruso&lt;/script&gt;', false)->assertDontSee('<script>intruso</script>', false)->assertDontSee('TEC-17');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "servicios_tecnologicos"') || str_contains($query['query'], 'from "servicio_publicaciones"')));
        $this->get('/admin/servicios?estado=activo&page=2')->assertOk()->assertSee('TEC-16')->assertDontSee('TEC-17');
        $this->get('/admin/servicios?estado=inactivo')->assertOk()->assertSee('1 coincidencias')->assertSee('TEC-17');
        $this->getJson('/admin/servicios?estado=activo')->assertOk()->assertJsonPath('first_page_url', url('/admin/servicios').'?page=1');
    }
}
