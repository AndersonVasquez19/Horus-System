<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\RevierteCadenaCrm;
use Tests\TestCase;

/**
 * Migraciones 000043 (permisos de clientes) y 000044 (tablas). SQLite: los `CHECK` de PostgreSQL solo se añaden en ese
 * motor, así que aquí se prueban la forma de las tablas, la unicidad del documento y la reversibilidad.
 */
final class ClientesMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000043_add_clientes_permissions.php');
    }

    private function tablas(): Migration
    {
        return require database_path('migrations/2026_11_15_000044_create_clientes_tables.php');
    }

    public function test_instalacion_nueva_crea_los_permisos_sin_roles(): void
    {
        foreach (RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS as $permiso) {
            $this->assertDatabaseHas('permissions', ['name' => $permiso, 'guard_name' => 'web']);
        }
        $this->assertSame(0, Role::query()->count());
    }

    public function test_base_ya_sembrada_los_concede_solo_a_admin_sin_resincronizar(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $this->permisos()->up();

        foreach (RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
    }

    public function test_los_permisos_son_repetibles_y_reversibles_sin_tocar_otros(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $migracion = $this->permisos();

        $migracion->up();
        $migracion->up();
        $this->assertSame(3, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS)->count());

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CLIENTES_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('cotizaciones.ver'));
    }
}
