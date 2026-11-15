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

    public function test_las_tablas_tienen_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('oportunidades', [
            'identificador', 'cliente_id', 'prospecto_id', 'servicio_id', 'cotizacion_id', 'titulo', 'etapa', 'monto_estimado', 'moneda',
            'probabilidad', 'fecha_cierre_estimada', 'motivo_perdida', 'responsable_id', 'cerrada_at', 'revision', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('oportunidad_historial', ['oportunidad_id', 'etapa_anterior', 'etapa_nueva', 'user_id', 'created_at']));
    }

    public function test_un_cliente_con_oportunidades_no_se_puede_eliminar_y_el_historial_tampoco_se_borra_en_cascada(): void
    {
        $cliente = DB::table('clientes')->insertGetId([
            'identificador' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'tipo' => 'persona', 'nombre' => 'X', 'estado' => 'activo', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oportunidad = DB::table('oportunidades')->insertGetId([
            'identificador' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cliente_id' => $cliente, 'titulo' => 'x', 'etapa' => 'nueva', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('oportunidad_historial')->insert(['oportunidad_id' => $oportunidad, 'etapa_nueva' => 'nueva', 'created_at' => now()]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        try {
            DB::transaction(fn () => DB::table('oportunidades')->where('id', $oportunidad)->delete());
            $this->fail('El historial debe impedir borrar la oportunidad.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('oportunidad_historial')->count());
        }
        try {
            DB::transaction(fn () => DB::table('clientes')->where('id', $cliente)->delete());
            $this->fail('Las oportunidades deben impedir borrar el cliente.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('oportunidades')->count());
        }
    }

    public function test_el_down_quita_las_tablas(): void
    {
        $this->revertirYRestaurar('2026_11_15_000049_create_oportunidades_tables', function (): void {
            $this->assertFalse(Schema::hasTable('oportunidades'));
            $this->assertFalse(Schema::hasTable('oportunidad_historial'));
        });
        $this->assertTrue(Schema::hasTable('oportunidades'));
    }
}
