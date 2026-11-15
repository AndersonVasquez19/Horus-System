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
 * Migraciones 000050 (permisos de proyectos) y 000051 (proyectos, hitos y secuencias). SQLite: los `CHECK` de PostgreSQL solo se
 * añaden en ese motor, así que aquí se prueban los permisos, la forma de las tablas y que no hay borrados en cascada.
 */
final class ProyectosMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000050_add_proyectos_permissions.php');
    }

    public function test_base_ya_sembrada_los_concede_solo_a_admin_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROYECTOS_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(3, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROYECTOS_PERMISSIONS)->count());
        foreach (RolesAndPermissionsSeeder::PROYECTOS_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROYECTOS_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('pipeline.ver'));
    }

    public function test_las_tablas_tienen_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('proyectos', [
            'identificador', 'codigo', 'cliente_id', 'oportunidad_id', 'nombre', 'descripcion', 'estado', 'fecha_inicio',
            'fecha_fin_estimada', 'fecha_fin_real', 'presupuesto', 'moneda', 'responsable_id', 'revision', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('proyecto_hitos', ['identificador', 'proyecto_id', 'nombre', 'orden', 'fecha_objetivo', 'completado_at']));
        $this->assertTrue(Schema::hasColumns('secuencias_codigo', ['tipo', 'anio', 'ultimo']));
    }

    public function test_el_codigo_y_el_contador_son_unicos_y_una_oportunidad_da_un_solo_proyecto(): void
    {
        $cliente = DB::table('clientes')->insertGetId([
            'identificador' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'tipo' => 'persona', 'nombre' => 'X', 'estado' => 'activo', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oportunidad = DB::table('oportunidades')->insertGetId([
            'identificador' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cliente_id' => $cliente, 'titulo' => 'x', 'etapa' => 'ganada', 'cerrada_at' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = fn (string $uuid, string $codigo, ?int $op): array => [
            'identificador' => $uuid, 'codigo' => $codigo, 'cliente_id' => $cliente, 'oportunidad_id' => $op, 'nombre' => 'x',
            'estado' => 'planificado', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('proyectos')->insert($fila('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'PRY-2026-0001', $oportunidad));
        // Sin oportunidad conviven varios (el nulo no colisiona).
        DB::table('proyectos')->insert($fila('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'PRY-2026-0002', null));
        DB::table('proyectos')->insert($fila('ffffffff-ffff-4fff-8fff-ffffffffffff', 'PRY-2026-0003', null));

        try {
            DB::transaction(fn () => DB::table('proyectos')->insert($fila('11111111-1111-4111-8111-111111111111', 'PRY-2026-0004', $oportunidad)));
            $this->fail('Una oportunidad solo da un proyecto.');
        } catch (QueryException) {
            $this->assertSame(3, DB::table('proyectos')->count());
        }
        try {
            DB::transaction(fn () => DB::table('proyectos')->insert($fila('22222222-2222-4222-8222-222222222222', 'PRY-2026-0001', null)));
            $this->fail('El código es único.');
        } catch (QueryException) {
            $this->assertSame(3, DB::table('proyectos')->count());
        }

        DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => 2026, 'ultimo' => 3]);
        $this->expectException(QueryException::class);
        DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => 2026, 'ultimo' => 4]);
    }

    public function test_el_down_quita_las_tablas(): void
    {
        $this->revertirYRestaurar('2026_11_15_000051_create_proyectos_tables', function (): void {
            foreach (['proyectos', 'proyecto_hitos', 'secuencias_codigo'] as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla));
            }
        });
        $this->assertTrue(Schema::hasTable('proyectos'));
    }
}
