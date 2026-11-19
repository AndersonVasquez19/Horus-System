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
 * Migraciones 000052 (permisos de soporte) y 000053 (tickets y notas). SQLite: los `CHECK` de PostgreSQL solo se
 * añaden en ese motor, así que aquí se prueban los permisos, la forma de las tablas y que no hay borrados en cascada.
 */
final class SoporteMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000052_add_soporte_permissions.php');
    }

    public function test_base_ya_sembrada_los_concede_solo_a_admin_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::SOPORTE_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(3, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::SOPORTE_PERMISSIONS)->count());
        foreach (RolesAndPermissionsSeeder::SOPORTE_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::SOPORTE_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('pipeline.ver'));
    }
}
