<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Migración 000085: Docentes e Infraestructura dejan de tener tablas y columnas; las clases conservan fecha, horas y tema. */
final class RetiroTablasDocentesInfraestructuraMigracionTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_11_19_000085_drop_docentes_infraestructura.php');
    }

    public function test_una_instalacion_nueva_no_tiene_esas_tablas_ni_columnas(): void
    {
        foreach (['convocatoria_docentes', 'docentes', 'aulas', 'plataformas_virtuales', 'sedes'] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        foreach (['sede_id', 'aula_id', 'plataforma_virtual_id', 'exige_infraestructura'] as $columna) {
            $this->assertFalse(Schema::hasColumn('convocatorias', $columna), $columna);
        }
        foreach (['docente_id', 'aula_id', 'plataforma_virtual_id', 'enlace_virtual'] as $columna) {
            $this->assertFalse(Schema::hasColumn('sesiones_clase', $columna), $columna);
        }
        foreach (['lugar', 'docente'] as $columna) {
            $this->assertFalse(Schema::hasColumn('convocatoria_horarios', $columna), $columna);
        }
        $this->assertTrue(Schema::hasColumns('sesiones_clase', ['identificador', 'convocatoria_id', 'numero', 'fecha', 'hora_inicio', 'hora_fin', 'tema', 'estado', 'motivo_cancelacion', 'revision']));
        $this->assertTrue(Schema::hasTable('asistencias'), 'La asistencia cuelga de la sesión y de la matrícula, no del docente.');
    }

    public function test_repetirla_y_revertirla_no_fallan(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertFalse(Schema::hasTable('docentes'));
    }

    public function test_se_detiene_sin_cambiar_nada_si_una_tabla_tiene_datos(): void
    {
        Schema::create('sedes', function ($tabla): void {
            $tabla->id();
        });
        DB::table('sedes')->insert(['id' => 1]);

        try {
            $this->migracion()->up();
            $this->fail('Debió negarse a borrar una tabla con datos.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sedes', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('sedes'));
        $this->assertSame(1, DB::table('sedes')->count());
        $this->assertTrue(Schema::hasTable('sesiones_clase'));
    }
}
