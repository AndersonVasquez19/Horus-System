<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\User;
use App\Services\GestionRoles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Migración 000086: los permisos del panel de servicios desaparecen, pero `servicios.publicar` se conserva (marca editorial del
 * catálogo público) y no se puede perder al editar un rol desde Roles y permisos.
 */
final class RetiroPermisosServiciosPanelMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRADOS = ['panel.servicios.acceder', 'servicios.ver', 'servicios.crear', 'servicios.editar'];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000086_retire_servicios_panel_permissions.php');
    }

    public function test_una_instalacion_nueva_y_el_seeder_solo_conservan_la_marca_editorial(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::query()->whereIn('name', self::RETIRADOS)->count());
        $this->assertSame(['servicios.publicar'], RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS);
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('servicios.publicar'));
    }

    public function test_los_retira_con_sus_asignaciones_y_no_toca_servicios_publicar_ni_lo_demas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (self::RETIRADOS as $nombre) {
            Permission::findOrCreate($nombre, 'web');
        }
        Role::findByName('admin', 'web')->givePermissionTo(self::RETIRADOS);
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión ajena
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $antes = Permission::query()->count() - count(self::RETIRADOS);

        $this->migracion()->up();

        $this->assertSame(0, Permission::query()->whereIn('name', self::RETIRADOS)->count());
        $this->assertSame($antes, Permission::query()->count(), 'Solo se borran los 4 permisos de la lista.');
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('servicios.publicar'));
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
    }

    public function test_repetirla_y_revertirla_no_fallan_ni_recrean_nada(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertSame(0, Permission::query()->whereIn('name', self::RETIRADOS)->count());
    }

    public function test_servicios_publicar_no_se_ofrece_en_roles_y_editar_un_rol_no_lo_pierde(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $gestion = app(GestionRoles::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $rol = Role::findByName('admin', 'web');

        $this->assertNotContains('servicios.publicar', $gestion->permisos()->pluck('name')->all(), 'No se asigna desde la pantalla.');

        $visibles = $gestion->permisos()->pluck('name')->all();
        $gestion->actualizar($admin, $rol, 'admin', $visibles);

        $this->assertTrue($rol->fresh()->hasPermissionTo('servicios.publicar'), 'Editar el rol desde la pantalla conserva la marca oculta.');
    }
}
