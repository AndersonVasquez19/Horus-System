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
 * Migración 000042: instalación nueva y base ya sembrada antes de existir los permisos de entrada. La migración conserva su
 * propia lista (incluye `panel.servicios.acceder`, que la 000086 retira después: el panel de servicios ya no existe).
 */
final class PermisosEntradaPanelMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRADAS = ['panel.academico.acceder', 'panel.servicios.acceder'];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_12_000042_add_panel_acceso_permissions.php');
    }

    /** Simula una base sembrada con la matriz anterior: sin permisos de entrada. */
    private function baseAnterior(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', self::ENTRADAS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_instalacion_nueva_solo_deja_la_entrada_academica_aunque_no_existan_roles(): void
    {
        // La 000042 crea las dos entradas y la 000086 retira la de servicios.
        $this->assertDatabaseHas('permissions', ['name' => 'panel.academico.acceder', 'guard_name' => 'web']);
        $this->assertDatabaseMissing('permissions', ['name' => 'panel.servicios.acceder']);
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
        $this->assertSame(2, Permission::query()->whereIn('name', self::ENTRADAS)->count());

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', self::ENTRADAS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('usuarios.ver'));
    }

    public function test_el_seeder_solo_conoce_la_entrada_academica(): void
    {
        $this->assertContains('panel.academico.acceder', RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS);
        $this->assertContains('panel.academico.acceder', RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS);
        $this->assertSame(['panel.academico.acceder'], RolesAndPermissionsSeeder::PANEL_PERMISSIONS);
        $this->assertNotContains('panel.servicios.acceder', RolesAndPermissionsSeeder::PERMISSIONS);
    }
}
