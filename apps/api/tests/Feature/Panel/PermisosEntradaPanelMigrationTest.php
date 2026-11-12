<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Migración 000042: instalación nueva y base ya sembrada antes de existir los permisos de entrada.
 */
final class PermisosEntradaPanelMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_12_000042_add_panel_acceso_permissions.php');
    }

    /** Simula una base sembrada con la matriz anterior: sin permisos de entrada. */
    private function baseAnterior(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PANEL_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_instalacion_nueva_crea_los_permisos_aunque_no_existan_roles(): void
    {
        foreach (RolesAndPermissionsSeeder::PANEL_PERMISSIONS as $permiso) {
            $this->assertDatabaseHas('permissions', ['name' => $permiso, 'guard_name' => 'web']);
        }
        $this->assertSame(0, Role::query()->count());
    }

    public function test_base_sembrada_recibe_la_distribucion_aprobada_sin_resincronizar_roles(): void
    {
        $this->baseAnterior();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $this->migracion()->up();

        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('panel.academico.acceder'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('panel.servicios.acceder'));
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->assertTrue(Role::findByName($rol, 'web')->hasPermissionTo('panel.academico.acceder'));
            $this->assertFalse(Role::findByName($rol, 'web')->hasPermissionTo('panel.servicios.acceder'));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
    }

    public function test_es_repetible_y_reversible(): void
    {
        $this->baseAnterior();
        $migracion = $this->migracion();

        $migracion->up();
        $migracion->up();
        $this->assertSame(2, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PANEL_PERMISSIONS)->count());

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PANEL_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('usuarios.ver'));
    }

    public function test_el_seeder_coincide_con_la_distribucion_de_la_migracion(): void
    {
        $this->assertContains('panel.academico.acceder', RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS);
        $this->assertContains('panel.academico.acceder', RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS);
        $this->assertNotContains('panel.servicios.acceder', RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS);
        $this->assertNotContains('panel.servicios.acceder', RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS);
        $this->assertContains('panel.servicios.acceder', RolesAndPermissionsSeeder::PERMISSIONS);
    }
}
