<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Resumen de matrículas en la ficha de un estudiante (vista Blade). Antes vivía en AdminReportConsistencyTest; las
 * pruebas del reporte pasaron a Panel\ReportesConsistenciaPanelTest cuando el reporte salió de Blade (14/11/2026).
 * Se retirará o portará al panel junto con el módulo de estudiantes.
 */
class AdminEstudianteResumenMatriculasTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    public function test_student_summary_distinguishes_pending_paid_and_cancelled_and_excludes_deleted(): void
    {
        $pendiente = $this->enrollment($this->course('PEN'), 1);
        foreach (['pagada', 'anulada'] as $index => $estado) {
            Matricula::create([
                'estudiante_id' => $pendiente->estudiante_id, 'curso_id' => $this->course('CUR'.$index)->id,
                'fecha_matricula' => '2026-10-28', 'estado' => $estado,
            ]);
        }
        $deleted = Matricula::create([
            'estudiante_id' => $pendiente->estudiante_id, 'curso_id' => $this->course('DEL')->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ]);
        $deleted->delete();
        $this->actingAs($this->adminUser())->get(route('admin.estudiantes.show', $pendiente->estudiante_id))
            ->assertOk()->assertSee('3 registros · 2 activas')
            ->assertSee('Pendientes: 1')->assertSee('Pagadas: 1')->assertSee('Anuladas: 1');
    }

    private function course(string $codigo = 'CUR-REP'): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Datos de prueba',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150,
            'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 100, 'cupos_disponibles' => 100, 'estado' => 'activo',
        ]);
    }

    private function enrollment(Curso $curso, int $number, string $estado = 'pendiente'): Matricula
    {
        $estudiante = Estudiante::create([
            'dni' => (string) (71000000 + $number), 'nombres' => 'Ana', 'apellidos' => 'Prueba '.$number, 'estado' => 'activo',
        ]);

        return Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => $estado, 'monto_pagado' => 150,
        ]);
    }
}
