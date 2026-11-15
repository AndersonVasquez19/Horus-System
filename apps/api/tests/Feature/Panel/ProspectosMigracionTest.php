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
 * Migraciones 000046 (permisos de prospectos) y 000047 (tabla). SQLite: los `CHECK` de PostgreSQL solo se añaden en ese
 * motor, así que aquí se prueban los permisos, la forma de la tabla y la unicidad del vínculo con la cotización.
 */
final class ProspectosMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000046_add_prospectos_permissions.php');
    }

    public function test_base_ya_sembrada_los_concede_solo_a_admin_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROSPECTOS_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(4, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROSPECTOS_PERMISSIONS)->count());
        foreach (RolesAndPermissionsSeeder::PROSPECTOS_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::PROSPECTOS_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('clientes.ver'));
    }

    public function test_la_tabla_tiene_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('prospectos', [
            'identificador', 'nombre_contacto', 'empresa', 'email', 'telefono', 'origen', 'servicio_interes_id', 'estado',
            'motivo_descarte', 'responsable_id', 'proximo_seguimiento', 'solicitud_cotizacion_id', 'cliente_id', 'revision', 'deleted_at',
        ]));
    }

    public function test_una_solicitud_de_cotizacion_da_un_solo_prospecto(): void
    {
        $solicitud = DB::table('solicitudes_cotizacion')->insertGetId([
            'identificador' => '66666666-6666-4666-8666-666666666666', 'codigo' => 'COT-1', 'recibida_at' => now(),
            'necesidad_cliente' => 'x', 'contacto_nombre' => 'x', 'contacto_email' => 'x@example.test', 'estado' => 'recibida',
            'revision' => 1, 'origen' => 'administrativo', 'creador_ambito' => 1, 'creacion_clave_hash' => hash('sha256', 'a'), 'creacion_huella' => hash('sha256', 'b'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = fn (string $uuid): array => [
            'identificador' => $uuid, 'nombre_contacto' => 'X', 'origen' => 'cotizacion', 'estado' => 'nuevo', 'revision' => 1,
            'solicitud_cotizacion_id' => $solicitud, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('prospectos')->insert($fila('77777777-7777-4777-8777-777777777777'));
        // Los prospectos sin cotización conviven (el nulo no colisiona).
        DB::table('prospectos')->insert(['solicitud_cotizacion_id' => null] + $fila('88888888-8888-4888-8888-888888888888'));
        DB::table('prospectos')->insert(['solicitud_cotizacion_id' => null] + $fila('99999999-9999-4999-8999-999999999999'));

        $this->expectException(QueryException::class);
        DB::table('prospectos')->insert($fila('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
    }

    public function test_el_down_quita_la_tabla(): void
    {
        $this->revertirYRestaurar('2026_11_15_000047_create_prospectos_table', fn () => $this->assertFalse(Schema::hasTable('prospectos')));
        $this->assertTrue(Schema::hasTable('prospectos'));
    }
}
