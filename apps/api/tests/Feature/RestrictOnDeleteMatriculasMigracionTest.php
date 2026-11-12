<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El cambio a RESTRICT solo existe en PostgreSQL (no aplicado aún en ninguna base). Aquí se comprueba que
 * la migración es inofensiva en SQLite y que el SQL generado es el esperado.
 */
class RestrictOnDeleteMatriculasMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const ARCHIVO = '2026_11_10_000040_restrict_on_delete_matriculas';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::ARCHIVO.'.php');
    }

    public function test_las_claves_apuntan_a_columnas_y_tablas_existentes(): void
    {
        foreach ($this->migracion()::CLAVES as $clave) {
            $this->assertTrue(Schema::hasColumn('matriculas', $clave['columna']));
            $this->assertTrue(Schema::hasTable($clave['padre']));
        }
        $this->assertSame(['matriculas_estudiante_id_foreign', 'matriculas_curso_id_foreign'], array_keys($this->migracion()::CLAVES));
    }

    public function test_las_sentencias_son_las_esperadas(): void
    {
        $restrict = $this->migracion()::sentencias('restrict');
        $cascade = $this->migracion()::sentencias('cascade');

        $this->assertSame(
            'alter table matriculas drop constraint matriculas_curso_id_foreign, '
            .'add constraint matriculas_curso_id_foreign foreign key (curso_id) references cursos (id) on delete restrict',
            $restrict['matriculas_curso_id_foreign']
        );
        $this->assertStringEndsWith('references estudiantes (id) on delete cascade', $cascade['matriculas_estudiante_id_foreign']);
        foreach ([...$restrict, ...$cascade] as $sql) {
            $this->assertDoesNotMatchRegularExpression('/[;"\\\\]/', $sql);
        }
    }

    public function test_no_se_toca_matricula_notifications(): void
    {
        $this->assertStringNotContainsString('matricula_notifications', implode(' ', $this->migracion()::sentencias('restrict')));
    }

    public function test_up_y_down_no_hacen_nada_en_sqlite(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $migracion = $this->migracion();

        $migracion->up();
        $migracion->down();

        $this->assertTrue(Schema::hasTable('matriculas'));
    }
}
