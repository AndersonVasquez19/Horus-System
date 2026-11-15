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
use Tests\TestCase;

/**
 * Migraciones 000043 (permisos de clientes) y 000044 (tablas). SQLite: los `CHECK` de PostgreSQL solo se añaden en ese
 * motor, así que aquí se prueban la forma de las tablas, la unicidad del documento y la reversibilidad.
 */
final class ClientesMigracionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_las_tablas_tienen_la_forma_esperada_y_son_reversibles(): void
    {
        $this->assertTrue(Schema::hasColumns('clientes', ['identificador', 'tipo', 'nombre', 'documento_tipo', 'documento_numero', 'email', 'estado', 'responsable_id', 'revision', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('cliente_contactos', ['cliente_id', 'nombre', 'cargo', 'email', 'telefono', 'principal']));

        $this->tablas()->down();
        $this->assertFalse(Schema::hasTable('clientes'));
        $this->assertFalse(Schema::hasTable('cliente_contactos'));

        $this->tablas()->up();
        $this->assertTrue(Schema::hasTable('clientes'));
    }

    public function test_el_documento_es_unico_pero_los_nulos_conviven(): void
    {
        $fila = fn (string $uuid, ?string $tipo, ?string $numero): array => [
            'identificador' => $uuid, 'tipo' => 'persona', 'nombre' => 'X', 'documento_tipo' => $tipo, 'documento_numero' => $numero,
            'estado' => 'activo', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('clientes')->insert($fila('11111111-1111-4111-8111-111111111111', null, null));
        DB::table('clientes')->insert($fila('22222222-2222-4222-8222-222222222222', null, null));
        DB::table('clientes')->insert($fila('33333333-3333-4333-8333-333333333333', 'dni', '70000001'));

        $this->expectException(QueryException::class);
        DB::table('clientes')->insert($fila('44444444-4444-4444-8444-444444444444', 'dni', '70000001'));
    }

    public function test_un_cliente_con_contactos_no_se_borra_en_cascada(): void
    {
        $id = DB::table('clientes')->insertGetId([
            'identificador' => '55555555-5555-4555-8555-555555555555', 'tipo' => 'empresa', 'nombre' => 'Redes', 'estado' => 'activo',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cliente_contactos')->insert(['cliente_id' => $id, 'nombre' => 'Contacto', 'principal' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::statement('PRAGMA foreign_keys = ON');
        $this->expectException(QueryException::class);
        DB::table('clientes')->where('id', $id)->delete();
    }
}
