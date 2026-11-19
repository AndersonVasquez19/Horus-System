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

/** Migración 000080: los permisos del CRM comercial (retirado el 18/11/2026) desaparecen; el resto no se toca. */
final class RetiroPermisosCrmMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = [
        'clientes.ver', 'clientes.crear', 'clientes.editar',
        'prospectos.ver', 'prospectos.crear', 'prospectos.editar', 'prospectos.convertir',
        'pipeline.ver', 'pipeline.gestionar',
        'proyectos.ver', 'proyectos.crear', 'proyectos.editar',
        'soporte.ver', 'soporte.crear', 'soporte.gestionar',
        'reportes-comerciales.ver', 'reportes-comerciales.exportar',
    ];

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000080_retire_crm_permissions.php');
    }

    public function test_una_instalacion_nueva_y_el_seeder_no_tienen_permisos_del_crm(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::query()->whereIn('name', self::CRM)->count());
        $this->assertNotContains('clientes.ver', RolesAndPermissionsSeeder::PERMISSIONS);
        $this->assertSame(1, Permission::query()->where('name', 'matriculas.ver')->count(), 'El resto de permisos sigue.');
    }

    public function test_los_retira_con_sus_asignaciones_y_no_toca_los_demas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (self::CRM as $nombre) {
            Permission::findOrCreate($nombre, 'web');
        }
        $admin = Role::findByName('admin', 'web');
        $admin->givePermissionTo(self::CRM);
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual ajena al CRM
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $antes = Permission::query()->count() - count(self::CRM);

        $this->migracion()->up();

        $this->assertSame(0, Permission::query()->whereIn('name', self::CRM)->count());
        $this->assertSame($antes, Permission::query()->count(), 'Solo se borran los 17 permisos de la lista.');
        $this->assertSame(0, DB::table('role_has_permissions')->whereNotIn('permission_id', Permission::query()->pluck('id'))->count(), 'No quedan asignaciones huérfanas.');
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('matriculas.ver'));
    }

    public function test_repetirla_y_revertirla_no_fallan_ni_recrean_nada(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertSame(0, Permission::query()->whereIn('name', self::CRM)->count());
    }
}
