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
 * Migraciones 000055 (permisos de convocatorias) y 000056 (convocatorias y horarios). SQLite: los `CHECK` de PostgreSQL
 * solo se añaden en ese motor, así que aquí se prueban los permisos por rol, la forma de las tablas y las restricciones.
 */
final class ConvocatoriasMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000055_add_convocatorias_permissions.php');
    }

    public function test_instalacion_nueva_los_concede_por_rol(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertTrue(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
        }
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('convocatorias.ver'));
        foreach (['convocatorias.crear', 'convocatorias.editar', 'convocatorias.cerrar'] as $permiso) {
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
    }

    public function test_base_ya_sembrada_los_concede_igual_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa
        Role::findByName('coordinador', 'web')->revokePermissionTo('reportes.ver'); // retirada manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(4, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('convocatorias.cerrar'));
        $this->assertTrue(Role::findByName('coordinador', 'web')->hasPermissionTo('convocatorias.cerrar'));
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('convocatorias.ver'));
        $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo('convocatorias.crear'));
        // Lo concedido o retirado a mano no se toca.
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
        $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo('reportes.ver'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CONVOCATORIAS_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('cursos.ver'));
    }

    public function test_las_tablas_tienen_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('convocatorias', [
            'identificador', 'codigo', 'curso_id', 'nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos',
            'cupos_disponibles', 'estado', 'revision', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('convocatoria_horarios', ['convocatoria_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'lugar', 'docente']));
    }

    public function test_el_codigo_es_unico_y_ni_el_curso_ni_la_convocatoria_se_borran_con_hijos(): void
    {
        $curso = DB::table('cursos')->insertGetId([
            'codigo' => 'CUR-M', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 1, 'precio' => 1,
            'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 1, 'cupos_disponibles' => 1, 'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = fn (string $uuid, string $codigo): array => [
            'identificador' => $uuid, 'codigo' => $codigo, 'curso_id' => $curso, 'modalidad' => 'virtual', 'fecha_inicio' => '2026-12-01',
            'fecha_fin' => '2026-12-31', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'borrador', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $convocatoria = DB::table('convocatorias')->insertGetId($fila('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'CNV-2026-0001'));
        DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $convocatoria, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        try {
            DB::transaction(fn () => DB::table('convocatorias')->insert($fila('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'CNV-2026-0001')));
            $this->fail('El código es único.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('convocatorias')->count());
        }
        try {
            DB::transaction(fn () => DB::table('convocatorias')->where('id', $convocatoria)->delete());
            $this->fail('Los horarios deben impedir borrar la convocatoria.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('convocatoria_horarios')->count());
        }
        $this->expectException(QueryException::class);
        DB::table('cursos')->where('id', $curso)->delete();
    }

    public function test_el_down_quita_las_tablas(): void
    {
        $this->revertirYRestaurar('2026_11_15_000056_create_convocatorias_tables', function (): void {
            $this->assertFalse(Schema::hasTable('convocatorias'));
            $this->assertFalse(Schema::hasTable('convocatoria_horarios'));
        });
        $this->assertTrue(Schema::hasTable('convocatorias'));
        $this->assertTrue(Schema::hasTable('convocatoria_horarios'));
    }

    public function test_las_matriculas_ganan_una_convocatoria_opcional_sin_perder_las_existentes(): void
    {
        $this->assertTrue(Schema::hasColumn('matriculas', 'convocatoria_id'));

        $curso = DB::table('cursos')->insertGetId([
            'codigo' => 'CUR-V', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 1, 'precio' => 1,
            'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 1, 'cupos_disponibles' => 1, 'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $estudiante = DB::table('estudiantes')->insertGetId([
            'dni' => '12345678', 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'email' => 'ana@correo.test', 'telefono' => '999999999',
            'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $convocatoria = DB::table('convocatorias')->insertGetId([
            'identificador' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'codigo' => 'CNV-2026-0001', 'curso_id' => $curso, 'modalidad' => 'virtual',
            'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'borrador',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = ['estudiante_id' => $estudiante, 'curso_id' => $curso, 'fecha_matricula' => '2026-11-10', 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()];
        $sin = DB::table('matriculas')->insertGetId($fila);
        $this->assertNull(DB::table('matriculas')->where('id', $sin)->value('convocatoria_id'));

        DB::table('matriculas')->insert([...$fila, 'estado' => 'anulada', 'convocatoria_id' => $convocatoria]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->expectException(QueryException::class);
        DB::table('convocatorias')->where('id', $convocatoria)->delete();
    }

    public function test_el_down_de_la_columna_de_matriculas_es_reversible(): void
    {
        // SQLite no puede quitar una columna con clave foránea sin reconstruir la tabla (y perder sus triggers): su
        // `down()` solo retira el índice y volver a aplicar `up()` repetiría la columna. Se prueba en PostgreSQL.
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El down de la columna solo se ejercita en PostgreSQL.');
        }

        $this->revertirYRestaurar('2026_11_15_000057_add_convocatoria_id_to_matriculas', function (): void {
            $this->assertFalse(Schema::hasColumn('matriculas', 'convocatoria_id'));
        });
        $this->assertTrue(Schema::hasColumn('matriculas', 'convocatoria_id'));
    }
}
