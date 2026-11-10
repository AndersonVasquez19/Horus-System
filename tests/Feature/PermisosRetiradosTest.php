<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Permisos sin ninguna comprobación en rutas, controladores o vistas: se retiran sin borrar filas. */
class PermisosRetiradosTest extends TestCase
{
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

    public function test_la_pantalla_de_roles_los_oculta_aunque_existan_y_no_se_borran(): void
    {
        $admin = $this->adminUser();
        foreach (self::RETIRADOS as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findByName('coordinador')->givePermissionTo(['seguimientos.ver', 'seguimientos.crear']);

        $respuesta = $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk()->assertSee('aprobaciones.ver');
        foreach (self::RETIRADOS as $permiso) {
            $respuesta->assertDontSee($permiso);
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

        foreach (['admin.estudiantes.index', 'admin.cursos.index', 'admin.matriculas.index', 'admin.roles.index', 'admin.asistente.index'] as $ruta) {
            $this->actingAs($con)->get(route($ruta))->assertStatus($this->actingAs($sin)->get(route($ruta))->status());
        }
    }
}
