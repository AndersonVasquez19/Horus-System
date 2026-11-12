<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Panel de servicios tecnológicos: dashboard, servicios y cotizaciones (lectura) y aislamiento entre dominios.
 */
final class ServiciosPanelTest extends PanelApiTestCase
{
    private function como(User $user, string $ruta): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/'.$ruta);
    }

    private function servicio(string $codigo, string $estado = 'activo'): ServicioTecnologico
    {
        return ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => "Servicio {$codigo}",
            'categoria' => 'redes', 'estado' => $estado,
        ]);
    }

    private function cotizacion(string $codigo, string $estado = 'recibida', ?string $seguimiento = null, string $recibida = '2026-11-10 10:00:00'): SolicitudCotizacion
    {
        $servicio = $this->servicio("SRV-{$codigo}");

        return SolicitudCotizacion::forceCreate([
            'identificador' => (string) Str::uuid(), 'codigo' => $codigo, 'recibida_at' => $recibida,
            'servicio_id' => $servicio->id, 'servicio_uuid' => $servicio->identificador_publico,
            'servicio_nombre_historico' => 'Cableado', 'necesidad_cliente' => 'Necesidad confidencial',
            'contacto_nombre' => 'Cliente Privado', 'contacto_email' => 'cliente@privado.test', 'contacto_telefono' => '988777666',
            'estado' => $estado, 'proximo_seguimiento' => $seguimiento, 'creador_ambito' => 1,
            'creacion_clave_hash' => hash('sha256', $codigo), 'creacion_huella' => hash('sha256', 'h'.$codigo),
        ]);
    }

    private function usuarioConPermisos(array $permisos, array $roles = []): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $rol = Role::create(['name' => 'rol-prueba-'.uniqid(), 'guard_name' => 'web']);
        $rol->givePermissionTo($permisos);
        $user = User::factory()->create();
        $user->assignRole([$rol, ...$roles]);

        return $user;
    }

    public function test_coordinador_y_secretaria_no_acceden_al_panel_de_servicios(): void
    {
        foreach (['coordinador', 'secretaria'] as $rol) {
            $user = $this->usuarioConRol($rol, "{$rol}@horus.test");
            foreach (['servicios/resumen', 'servicios/servicios', 'servicios/cotizaciones'] as $ruta) {
                $this->como($user, $ruta)->assertForbidden()->assertJsonPath('code', 'prohibido');
            }
        }
    }

    public function test_tener_permisos_de_modulo_sin_permiso_de_entrada_no_abre_el_panel(): void
    {
        $user = $this->usuarioConPermisos(['servicios.ver', 'cotizaciones.ver'], ['admin']);
        $user->roles()->where('name', 'admin')->first()->revokePermissionTo('panel.servicios.acceder');

        $this->como($user->fresh(), 'servicios/servicios')->assertForbidden();
    }

    public function test_el_permiso_de_servicios_no_abre_el_panel_academico(): void
    {
        $user = $this->usuarioConPermisos(['panel.servicios.acceder', 'servicios.ver']);

        $this->como($user, 'academico/resumen')->assertForbidden();
        $this->como($user, 'servicios/servicios')->assertOk();
    }

    public function test_resumen_de_admin_con_servicios_y_cotizaciones_sin_contacto(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->servicio('S-1');
        $this->servicio('S-2', 'inactivo');
        $this->cotizacion('COT-1');
        $this->cotizacion('COT-2', 'en_revision', '2026-01-01');

        $respuesta = $this->como($admin, 'servicios/resumen')->assertOk();

        // Cada cotización de prueba enlaza su propio servicio activo: 1 + 2 activos y 1 inactivo.
        $respuesta->assertJsonPath('data.indicadores.servicios_activos', 3)
            ->assertJsonPath('data.indicadores.servicios_total', 4)
            ->assertJsonPath('data.indicadores.cotizaciones_recibidas', 1)
            ->assertJsonPath('data.indicadores.cotizaciones_en_revision', 1)
            ->assertJsonPath('data.indicadores.seguimientos_pendientes', 1)
            ->assertJsonCount(2, 'data.bloques.ultimas_cotizaciones');
        $cuerpo = json_encode($respuesta->json(), JSON_THROW_ON_ERROR);
        foreach (['Cliente Privado', 'cliente@privado.test', '988777666', 'Necesidad confidencial'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $cuerpo);
        }
    }

    public function test_sin_rol_admin_las_cotizaciones_no_aparecen_ni_se_listan(): void
    {
        $user = $this->usuarioConPermisos(['panel.servicios.acceder', 'servicios.ver', 'cotizaciones.ver']);
        $this->cotizacion('COT-1');

        $this->como($user, 'servicios/resumen')->assertOk()
            ->assertJsonMissingPath('data.indicadores.cotizaciones_recibidas')
            ->assertJsonMissingPath('data.bloques.ultimas_cotizaciones');
        $this->como($user, 'servicios/cotizaciones')->assertForbidden();
    }

    public function test_listado_de_servicios_con_filtros(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->servicio('CAB-01');
        $this->servicio('CAM-01', 'inactivo');

        $this->como($admin, 'servicios/servicios?estado=activo')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.codigo', 'CAB-01')
            ->assertJsonPath('data.0.publicacion', null);
        $this->como($admin, 'servicios/servicios?publicacion=sin_ficha')->assertJsonPath('meta.total', 2);
        $this->como($admin, 'servicios/servicios?busqueda=cam')->assertJsonPath('data.0.codigo', 'CAM-01');
    }

    public function test_bandeja_de_cotizaciones_filtra_y_minimiza(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->cotizacion('COT-1', 'recibida', null, '2026-11-01 15:00:00');
        $this->cotizacion('COT-2', 'en_revision', '2026-01-01', '2026-11-05 15:00:00');

        $todas = $this->como($admin, 'servicios/cotizaciones')->assertOk();
        $todas->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.codigo', 'COT-2');
        $this->assertSame(
            ['identificador', 'codigo', 'servicio', 'servicio_uuid', 'origen', 'estado', 'responsable', 'recibida_en', 'proximo_seguimiento'],
            array_keys($todas->json('data.0')),
        );

        $this->como($admin, 'servicios/cotizaciones?estado=recibida')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'COT-1');
        $this->como($admin, 'servicios/cotizaciones?seguimiento_pendiente=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'COT-2');
        $this->como($admin, 'servicios/cotizaciones?desde=2026-11-03&hasta=2026-11-30')->assertJsonPath('meta.total', 1);
        $this->como($admin, 'servicios/cotizaciones?desde=2026-02-31&estado=cerrada')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.filtros', []);
    }
}
