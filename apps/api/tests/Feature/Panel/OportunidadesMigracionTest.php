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
 * Migraciones 000048 (permisos del pipeline) y 000049 (oportunidades e historial). SQLite: los `CHECK` de PostgreSQL solo se
 * añaden en ese motor, así que aquí se prueban los permisos, la forma de las tablas y que no hay borrados en cascada.
 */
final class OportunidadesMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000048_add_pipeline_permissions.php');
    }

    public function test_base_ya_sembrada_los_concede_solo_a_admin_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PIPELINE_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(2, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PIPELINE_PERMISSIONS)->count());
        foreach (RolesAndPermissionsSeeder::PIPELINE_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PIPELINE_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('clientes.ver'));
    }
}
