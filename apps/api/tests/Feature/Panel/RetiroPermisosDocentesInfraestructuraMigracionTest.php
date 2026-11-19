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

/** Migración 000084: los permisos de Docentes, Infraestructura y del enlace de clase desaparecen; el resto no se toca. */
final class RetiroPermisosDocentesInfraestructuraMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRADOS = [
        'docentes.ver', 'docentes.crear', 'docentes.editar', 'docentes.eliminar', 'docentes.restaurar',
        'infraestructura.ver', 'infraestructura.gestionar', 'sesiones.ver-enlace',
    ];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000084_retire_docentes_infraestructura_permissions.php');
    }

    public function test_una_instalacion_nueva_y_el_seeder_no_los_tienen(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::query()->whereIn('name', self::RETIRADOS)->count());
        foreach (self::RETIRADOS as $nombre) {
            $this->assertNotContains($nombre, RolesAndPermissionsSeeder::PERMISSIONS);
        }
        $this->assertSame(1, Permission::query()->where('name', 'sesiones.cancelar')->count(), 'El resto de permisos de clases sigue.');
    }

    public function test_los_retira_con_sus_asignaciones_y_no_toca_los_demas(): void
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
        $this->assertSame($antes, Permission::query()->count(), 'Solo se borran los permisos de la lista.');
        $this->assertSame(0, DB::table('role_has_permissions')->whereNotIn('permission_id', Permission::query()->pluck('id'))->count());
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('sesiones.crear'));
    }

    public function test_repetirla_y_revertirla_no_fallan_ni_recrean_nada(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertSame(0, Permission::query()->whereIn('name', self::RETIRADOS)->count());
    }
}
