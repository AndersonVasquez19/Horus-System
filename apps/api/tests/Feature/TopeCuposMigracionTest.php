<?php

namespace Tests\Feature;

use App\Models\Curso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Migración 000082: lleva al tope (10) los cursos con más cupos sin quitar ningún cupo ocupado. */
class TopeCuposMigracionTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_19_000082_limit_cupos_to_ten.php');
    }

    private function curso(string $codigo, int $max, int $disponibles): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Descripción.', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lunes', 'estado' => 'activo',
            'cupos_maximos' => $max, 'cupos_disponibles' => $disponibles,
        ]);
    }

    public function test_baja_al_tope_conserva_lo_ocupado_y_es_repetible(): void
    {
        $grande = $this->curso('CUR-G', 30, 25);      // 5 ocupados → máximo 10, disponibles 5
        $saturado = $this->curso('CUR-S', 20, 5);     // 15 ocupados → conserva 15 (no se quita ninguna matrícula)
        $chico = $this->curso('CUR-C', 8, 3);         // ya cumple: no cambia

        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertSame([10, 5], [$grande->fresh()->cupos_maximos, $grande->fresh()->cupos_disponibles]);
        $this->assertSame([15, 0], [$saturado->fresh()->cupos_maximos, $saturado->fresh()->cupos_disponibles]);
        $this->assertSame([8, 3], [$chico->fresh()->cupos_maximos, $chico->fresh()->cupos_disponibles]);
        $this->assertSame(0, DB::table('cursos')->whereColumn('cupos_disponibles', '>', 'cupos_maximos')->count());
    }
}
