<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelCotizacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->withoutVite();
        $this->actingAs($this->adminUser());
    }

    private function crear(): string
    {
        return $this->postJson('/admin/cotizaciones', ['contacto_nombre' => 'Contacto privado ficticio', 'contacto_email' => 'privado@example.test',
            'necesidad_cliente' => 'Necesidad privada exclusiva'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('solicitud.identificador');
    }

    public function test_html_privado_minimizado_y_json_original_sin_escrituras_de_lectura(): void
    {
        $uuid = $this->crear();
        $auditorias = AuditLog::count();
        $this->get('/admin/cotizaciones')->assertOk()->assertViewIs('admin.cotizaciones.index')
            ->assertSee('data-panel-cotizaciones', false)->assertSee($uuid)->assertSee('Nueva solicitud')
            ->assertDontSee('privado@example.test')->assertDontSee('Contacto privado ficticio')->assertDontSee('Necesidad privada exclusiva');
        $this->getJson('/admin/cotizaciones')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.contacto_nombre');
        $this->getJson('/admin/cotizaciones/'.$uuid)->assertOk()->assertJsonPath('solicitud.contacto_email', 'privado@example.test');
        $this->assertSame($auditorias, AuditLog::count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_filtros_y_paginacion_preservan_el_contrato(): void
    {
        $uuid = $this->crear();
        $etag = $this->getJson('/admin/cotizaciones/'.$uuid)->headers->get('ETag');
        $this->patchJson('/admin/cotizaciones/'.$uuid.'/estado', ['estado' => 'en_revision'], ['If-Match' => $etag])->assertOk();
        $this->get('/admin/cotizaciones?estado=recibida')->assertOk()->assertSee('0 coincidencias')->assertDontSee($uuid);
        $this->get('/admin/cotizaciones?estado=en_revision&por_pagina=1')->assertOk()->assertSee($uuid)->assertSee('En revisión');
        $this->getJson('/admin/cotizaciones?estado=cotizada')->assertUnprocessable();
        $this->get('/admin/cotizaciones')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_lector_ve_historial_y_detalle_sin_formularios_de_escritura(): void
    {
        $uuid = $this->crear();
        $lector = User::factory()->create();
        $lector->assignRole('admin');
        Role::findByName('admin')->revokePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
        $lector->givePermissionTo('cotizaciones.ver');
        $this->actingAs($lector)->get('/admin/cotizaciones')->assertOk()->assertSee('title="Solicitudes de cotización"', false)
            ->assertDontSee('data-form="informacion"', false)->assertDontSee('data-form="seguimiento"', false)->assertDontSee('Nueva solicitud');
        $this->getJson('/admin/cotizaciones/'.$uuid.'/historial')->assertOk()->assertJsonPath('data.0.evento', 'creada');
        $this->patchJson('/admin/cotizaciones/'.$uuid.'/estado', ['estado' => 'en_revision'])->assertForbidden();
    }

    public function test_permiso_de_crear_o_servicios_no_concede_interfaz_ni_navegacion(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('coordinador');
        $usuario->givePermissionTo(['cotizaciones.crear', 'servicios.ver']);
        $this->actingAs($usuario)->get('/admin/cotizaciones')->assertForbidden();
        $this->get('/admin/servicios')->assertOk()->assertDontSee('title="Solicitudes de cotización"', false);
    }

    public function test_escapado_y_ausencia_de_datos_personales_en_titulo_y_url(): void
    {
        $this->crear();
        $this->get('/admin/cotizaciones')->assertOk()->assertSee('<title>Solicitudes de cotización | Horus Group SRL</title>', false)
            ->assertDontSee('privado@example.test')->assertSee('autocomplete="off"', false);
        $this->app['auth']->forgetGuards();
        $this->get('/admin/cotizaciones')->assertRedirect('/login');
    }

    public function test_html_paginado_escapa_snapshot_heredado_y_conserva_filtros(): void
    {
        $uuid = $this->crear();
        $servicio = new ServicioTecnologico(['nombre' => 'Servicio ficticio', 'estado' => 'inactivo']);
        // Campos de identidad asignados por servidor en el módulo real; fixture histórica sintética.
        $servicio->codigo = 'DEMO-PANEL';
        $servicio->identificador_publico = (string) Str::uuid();
        $servicio->save();
        SolicitudCotizacion::where('identificador', $uuid)->update(['servicio_id' => $servicio->id,
            'servicio_uuid' => $servicio->identificador_publico, 'servicio_nombre_historico' => '<script>synthetic()</script>']);
        $this->crear();
        $this->get('/admin/cotizaciones?estado=recibida&por_pagina=1')->assertOk()
            ->assertSee('&lt;script&gt;synthetic()&lt;/script&gt;', false)->assertDontSee('<script>synthetic()</script>', false)
            ->assertSee('2 coincidencias')->assertSee('por_pagina=1', false)->assertSee('page=2', false);
    }

    public function test_editor_y_gestor_reciben_solo_sus_acciones_con_permiso_lectura(): void
    {
        foreach (['editar', 'gestionar'] as $permiso) {
            $usuario = User::factory()->create();
            $usuario->assignRole('admin');
            Role::findByName('admin')->revokePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
            $usuario->givePermissionTo(['cotizaciones.ver', 'cotizaciones.'.$permiso]);
            $respuesta = $this->actingAs($usuario)->get('/admin/cotizaciones')->assertOk()->assertDontSee('Nueva solicitud');
            if ($permiso === 'editar') {
                $respuesta->assertSee('data-form="informacion"', false)->assertDontSee('data-form="seguimiento"', false);
            } else {
                $respuesta->assertDontSee('data-form="informacion"', false)->assertSee('data-form="seguimiento"', false);
            }
        }
    }

    public function test_navegacion_y_html_exigen_admin_y_lectura_aunque_existan_todos_los_permisos(): void
    {
        $this->get('/admin')->assertOk()->assertSee('title="Solicitudes de cotización"', false);
        foreach (['coordinador', 'secretaria', 'admin'] as $rol) {
            $usuario = User::factory()->create();
            $usuario->assignRole($rol);
            if ($rol === 'admin') {
                Role::findByName('admin')->revokePermissionTo('cotizaciones.ver');
            } else {
                $usuario->givePermissionTo(RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS);
            }
            $this->actingAs($usuario)->get('/admin')->assertOk()->assertDontSee('title="Solicitudes de cotización"', false);
            $this->get('/admin/cotizaciones')->assertForbidden();
        }
    }
}
