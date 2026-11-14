<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\LlamaEstudiantesDelPanel;
use Tests\TestCase;

/**
 * Matrículas en la ficha de un estudiante, ahora por la API del panel (`data.matriculas`; Blade retirado el 14/11/2026).
 * Antes vivía en AdminReportConsistencyTest. El detalle trae solo estado y fecha de cada matrícula vigente en el
 * registro (sin las eliminadas); los contadores «activas», «Pendientes», «Pagadas» y «Anuladas» los calcula la UI de Next
 * a partir de esa lista, así que aquí se comprueba la lista que los alimenta. Se conserva `DatabaseMigrations`.
 */
class AdminEstudianteResumenMatriculasTest extends TestCase
{
    use DatabaseMigrations;
    use LlamaEstudiantesDelPanel;

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
        $this->actingAs($this->adminUser());
        $matriculas = $this->panelEstudiantes('GET', (string) $pendiente->estudiante_id)->assertOk()->json('data.matriculas');

        // Tres registros (la eliminada no cuenta): una pendiente, una pagada y una anulada; dos siguen activas.
        $estados = array_column($matriculas, 'estado');
        sort($estados);
        $this->assertSame(['anulada', 'pagada', 'pendiente'], $estados);
        $this->assertCount(2, array_filter($estados, fn (string $estado): bool => $estado !== 'anulada'));
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
