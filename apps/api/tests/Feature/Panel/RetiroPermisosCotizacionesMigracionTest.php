<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Migración 000081: los permisos de cotizaciones desaparecen; el resto no se toca. */
final class RetiroPermisosCotizacionesMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const COTIZACIONES = ['cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'cotizaciones.gestionar'];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000081_retire_cotizaciones_permissions.php');
    }

    public function test_una_instalacion_nueva_y_el_seeder_no_tienen_permisos_de_cotizaciones(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::query()->whereIn('name', self::COTIZACIONES)->count());
        $this->assertNotContains('cotizaciones.ver', RolesAndPermissionsSeeder::PERMISSIONS);
        $this->assertSame(1, Permission::query()->where('name', 'servicios.publicar')->count(), 'Los permisos del catálogo de servicios siguen.');
    }

    public function test_los_retira_con_sus_asignaciones_y_no_toca_los_demas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (self::COTIZACIONES as $nombre) {
            Permission::findOrCreate($nombre, 'web');
        }
        Role::findByName('admin', 'web')->givePermissionTo(self::COTIZACIONES);
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión ajena
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $antes = Permission::query()->count() - count(self::COTIZACIONES);

        $this->migracion()->up();

        $this->assertSame(0, Permission::query()->whereIn('name', self::COTIZACIONES)->count());
        $this->assertSame($antes, Permission::query()->count(), 'Solo se borran los 4 permisos de la lista.');
        $this->assertSame(0, DB::table('role_has_permissions')->whereNotIn('permission_id', Permission::query()->pluck('id'))->count());
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('servicios.publicar'));
    }

    public function test_repetirla_y_revertirla_no_fallan_ni_recrean_nada(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertSame(0, Permission::query()->whereIn('name', self::COTIZACIONES)->count());
    }
}
