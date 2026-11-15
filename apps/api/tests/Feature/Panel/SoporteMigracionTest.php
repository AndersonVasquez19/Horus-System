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

    public function test_las_tablas_tienen_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('tickets_soporte', [
            'identificador', 'codigo', 'cliente_id', 'proyecto_id', 'asunto', 'descripcion', 'prioridad', 'estado', 'canal',
            'asignado_a', 'vence_at', 'resuelto_at', 'cerrado_at', 'revision', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ticket_notas', ['ticket_id', 'autor_id', 'interna', 'texto', 'created_at']));
    }

    public function test_el_codigo_es_unico_y_ni_el_cliente_ni_el_ticket_se_borran_con_notas(): void
    {
        $cliente = DB::table('clientes')->insertGetId([
            'identificador' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'tipo' => 'persona', 'nombre' => 'X', 'estado' => 'activo', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = fn (string $uuid, string $codigo): array => [
            'identificador' => $uuid, 'codigo' => $codigo, 'cliente_id' => $cliente, 'asunto' => 'x', 'prioridad' => 'media', 'estado' => 'abierto',
            'canal' => 'web', 'vence_at' => now(), 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];
        $ticket = DB::table('tickets_soporte')->insertGetId($fila('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'SOP-2026-0001'));
        DB::table('ticket_notas')->insert(['ticket_id' => $ticket, 'interna' => true, 'texto' => 'x', 'created_at' => now()]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        try {
            DB::transaction(fn () => DB::table('tickets_soporte')->insert($fila('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'SOP-2026-0001')));
            $this->fail('El código es único.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('tickets_soporte')->count());
        }
        try {
            DB::transaction(fn () => DB::table('tickets_soporte')->where('id', $ticket)->delete());
            $this->fail('Las notas deben impedir borrar el ticket.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('ticket_notas')->count());
        }
        $this->expectException(QueryException::class);
        DB::table('clientes')->where('id', $cliente)->delete();
    }

    public function test_el_down_quita_las_tablas(): void
    {
        $this->revertirYRestaurar('2026_11_15_000053_create_tickets_soporte_tables', function (): void {
            $this->assertFalse(Schema::hasTable('tickets_soporte'));
            $this->assertFalse(Schema::hasTable('ticket_notas'));
        });
        $this->assertTrue(Schema::hasTable('tickets_soporte'));
    }
}
