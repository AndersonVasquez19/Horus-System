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
}
