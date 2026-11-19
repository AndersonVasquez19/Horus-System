<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\LlamaRolesDelPanel;
use Tests\TestCase;

/** Permisos sin ninguna comprobación en rutas, controladores o vistas: se retiran sin borrar filas. */
class PermisosRetiradosTest extends TestCase
{
    use LlamaRolesDelPanel;
    use RefreshDatabase;

    private const RETIRADOS = [
        'seguimientos.ver',
        'seguimientos.crear',
        'seguimientos.editar',
        'seguimientos.eliminar',
        'asistente.entrenar',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
    }

    public function test_una_instalacion_nueva_no_los_crea_ni_los_concede(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::whereIn('name', self::RETIRADOS)->count());
        $this->assertSame([], array_values(array_intersect(self::RETIRADOS, RolesAndPermissionsSeeder::PERMISSIONS)));
        $this->assertSame([], array_values(array_intersect(self::RETIRADOS, RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS)));
        $this->assertSame([], array_values(array_intersect(self::RETIRADOS, RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS)));
    }

    public function test_la_api_de_roles_los_oculta_aunque_existan_y_no_se_borran(): void
    {
        $admin = $this->adminUser();
        foreach (self::RETIRADOS as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findByName('coordinador')->givePermissionTo(['seguimientos.ver', 'seguimientos.crear']);

        // La lista de roles la sirve el panel: ni el catálogo (`meta.permisos`) ni los permisos de cada rol los incluyen.
        $respuesta = $this->actingAs($admin)->llamarRolesPanel('GET')->assertOk();
        $this->assertContains('aprobaciones.ver', $respuesta->json('meta.permisos'));
        $coordinador = collect($respuesta->json('data'))->firstWhere('nombre', 'coordinador');
        foreach (self::RETIRADOS as $permiso) {
            $this->assertNotContains($permiso, $respuesta->json('meta.permisos'));
            $this->assertNotContains($permiso, $coordinador['permisos']);
        }

        $this->assertSame(5, Permission::whereIn('name', self::RETIRADOS)->count());
        $this->assertTrue(Role::findByName('coordinador')->hasPermissionTo('seguimientos.ver'));
    }

    public function test_tenerlos_o_no_no_cambia_el_acceso_a_ninguna_pantalla(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (self::RETIRADOS as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $con = User::factory()->create();
        $con->assignRole('secretaria')->givePermissionTo(self::RETIRADOS);
        $sin = User::factory()->create();
        $sin->assignRole('secretaria');

        config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
        $cabecera = ['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789'];
        foreach (['academico/estudiantes', 'academico/cursos', 'academico/matriculas', 'compartido/asistente'] as $ruta) {
            $this->actingAs($con)->getJson('/api/panel/v1/'.$ruta, $cabecera)
                ->assertStatus($this->actingAs($sin)->getJson('/api/panel/v1/'.$ruta, $cabecera)->status());
        }
    }
}
