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
 * Migraciones 000058 (permisos), 000059 (evaluaciones y calificaciones) y 000060 (`matriculas.nota_final` y `resultado`).
 * SQLite: los `CHECK` de PostgreSQL solo se añaden en ese motor, así que aquí se prueban los permisos por rol, la forma de
 * las tablas y las restricciones que sí existen en ambos motores.
 */
final class CalificacionesMigracionTest extends TestCase
{
    use RefreshDatabase;
    use RevierteCadenaCrm;

    private function permisos(): Migration
    {
        return require database_path('migrations/2026_11_15_000058_add_calificaciones_permissions.php');
    }

    public function test_instalacion_nueva_los_concede_por_rol(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (RolesAndPermissionsSeeder::CALIFICACIONES_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo($permiso));
            $this->assertTrue(Role::findByName('coordinador', 'web')->hasPermissionTo($permiso));
        }
        foreach (['calificaciones.ver', 'calificaciones.registrar'] as $permiso) {
            $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
        foreach (['calificaciones.corregir', 'calificaciones.exportar'] as $permiso) {
            $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo($permiso));
        }
    }

    public function test_base_ya_sembrada_los_concede_igual_sin_resincronizar_y_es_reversible(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CALIFICACIONES_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('secretaria', 'web')->givePermissionTo('cursos.editar'); // concesión manual previa
        Role::findByName('coordinador', 'web')->revokePermissionTo('reportes.ver'); // retirada manual previa

        $migracion = $this->permisos();
        $migracion->up();
        $migracion->up();

        $this->assertSame(4, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CALIFICACIONES_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('coordinador', 'web')->hasPermissionTo('calificaciones.exportar'));
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('calificaciones.registrar'));
        $this->assertFalse(Role::findByName('secretaria', 'web')->hasPermissionTo('calificaciones.corregir'));
        $this->assertTrue(Role::findByName('secretaria', 'web')->hasPermissionTo('cursos.editar'));
        $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo('reportes.ver'));

        $migracion->down();
        $this->assertSame(0, Permission::query()->whereIn('name', RolesAndPermissionsSeeder::CALIFICACIONES_PERMISSIONS)->count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('cursos.ver'));
    }

    public function test_las_tablas_y_las_columnas_tienen_la_forma_esperada(): void
    {
        $this->assertTrue(Schema::hasColumns('evaluaciones', ['identificador', 'convocatoria_id', 'nombre', 'peso', 'fecha', 'orden']));
        $this->assertTrue(Schema::hasColumns('calificaciones', ['matricula_id', 'evaluacion_id', 'nota', 'observacion', 'registrado_por']));
        $this->assertTrue(Schema::hasColumns('matriculas', ['nota_final', 'resultado']));
    }

    public function test_unicidad_de_la_nota_y_restricciones_sin_cascada(): void
    {
        $usuario = DB::table('users')->insertGetId(['name' => 'Coordinador', 'email' => 'c@horus.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $curso = DB::table('cursos')->insertGetId([
            'codigo' => 'CUR-N', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 1, 'precio' => 1,
            'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 1, 'cupos_disponibles' => 1, 'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $estudiante = DB::table('estudiantes')->insertGetId([
            'dni' => '12345678', 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'email' => 'ana@correo.test', 'telefono' => '999999999',
            'estado' => 'activo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $convocatoria = DB::table('convocatorias')->insertGetId([
            'identificador' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'codigo' => 'CNV-2026-0001', 'curso_id' => $curso, 'modalidad' => 'virtual',
            'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'en_curso',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $matricula = DB::table('matriculas')->insertGetId([
            'estudiante_id' => $estudiante, 'curso_id' => $curso, 'convocatoria_id' => $convocatoria, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pagada', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull(DB::table('matriculas')->where('id', $matricula)->value('nota_final'));
        $evaluacion = DB::table('evaluaciones')->insertGetId([
            'identificador' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'convocatoria_id' => $convocatoria, 'nombre' => 'Examen', 'peso' => 100,
            'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nota = ['matricula_id' => $matricula, 'evaluacion_id' => $evaluacion, 'nota' => 15, 'registrado_por' => $usuario, 'created_at' => now(), 'updated_at' => now()];
        DB::table('calificaciones')->insert($nota);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        try {
            DB::transaction(fn () => DB::table('calificaciones')->insert($nota));
            $this->fail('Una matrícula tiene una sola nota por evaluación.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('calificaciones')->count());
        }
        try {
            DB::transaction(fn () => DB::table('evaluaciones')->where('id', $evaluacion)->delete());
            $this->fail('Una evaluación con notas no se borra.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('evaluaciones')->count());
        }
        $this->expectException(QueryException::class);
        DB::table('matriculas')->where('id', $matricula)->delete();
    }

    public function test_el_down_quita_las_tablas_y_las_columnas(): void
    {
        $this->revertirYRestaurar('2026_11_15_000060_add_nota_final_to_matriculas', function (): void {
            $this->assertFalse(Schema::hasColumn('matriculas', 'nota_final'));
            $this->assertFalse(Schema::hasColumn('matriculas', 'resultado'));
        });
        $this->assertTrue(Schema::hasColumns('matriculas', ['nota_final', 'resultado']));

        $this->revertirYRestaurar('2026_11_15_000059_create_evaluaciones_calificaciones_tables', function (): void {
            $this->assertFalse(Schema::hasTable('evaluaciones'));
            $this->assertFalse(Schema::hasTable('calificaciones'));
        });
        $this->assertTrue(Schema::hasTable('evaluaciones'));
        $this->assertTrue(Schema::hasTable('calificaciones'));
    }
}
