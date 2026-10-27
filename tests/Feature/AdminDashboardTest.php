<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_current_academic_totals_and_recent_records(): void
    {
        $estudiante = Estudiante::create([
            'dni' => '70123456',
            'nombres' => 'Lucía',
            'apellidos' => 'Pérez Soto',
            'estado' => 'activo',
        ]);

        $curso = Curso::create([
            'codigo' => 'CUR-TEST',
            'nombre' => 'Curso de prueba',
            'descripcion' => 'Descripción del curso de prueba.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 10,
            'cupos_disponibles' => 10,
            'estado' => 'activo',
        ]);

        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada',
            'monto_pagado' => 150,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Estudiantes activos')
            ->assertSee('Cursos activos')
            ->assertSee('Matrículas del mes')
            ->assertSee('S/ 150.00')
            ->assertSee('Lucía Pérez Soto')
            ->assertSee('Curso de prueba');
    }
}
