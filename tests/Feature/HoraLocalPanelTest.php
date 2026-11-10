<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pulido del panel (A): las pantallas muestran hora de Lima y el «mes actual» del inicio es el de Lima.
 * Reloj fijado a las 23:30 de Lima del último día del mes (04:30 UTC del día siguiente). SQLite en memoria.
 */
class HoraLocalPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:30:00', 'America/Lima'));
    }

    private function matricula(string $fecha): Matricula
    {
        $curso = Curso::create([
            'codigo' => 'COD-LIMA-'.random_int(1000, 9999), 'nombre' => 'Curso', 'descripcion' => 'x',
            'categoria' => 'tecnologia', 'duracion_horas' => 10, 'precio' => 10, 'modalidad' => 'virtual',
            'horario' => 'x', 'cupos_maximos' => 5, 'cupos_disponibles' => 4, 'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => (string) random_int(70000000, 79999999), 'nombres' => 'Ana', 'apellidos' => 'Lima', 'estado' => 'activo']);

        return Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => $fecha,
            'estado' => 'pendiente', 'monto_pagado' => 0,
        ]);
    }

    public function test_el_inicio_usa_el_mes_y_la_fecha_de_lima(): void
    {
        $this->matricula('2026-11-30');

        $this->actingAs($this->adminUser())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('matriculasMes', 1)
            ->assertSee('Actualizado al 30/11/2026');
    }

    public function test_la_matricula_y_las_notificaciones_muestran_la_hora_de_lima(): void
    {
        $admin = $this->adminUser();
        $matricula = $this->matricula('2026-11-30');
        NotificacionInterna::create([
            'user_id' => $admin->id, 'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
            'titulo' => 'Aviso de prueba', 'mensaje' => 'x', 'tipo' => 'info',
        ]);

        $this->actingAs($admin)->get(route('admin.matriculas.show', $matricula))
            ->assertOk()->assertSee('30/11/2026 23:30')->assertDontSee('01/12/2026 04:30');

        $this->actingAs($admin)->get(route('admin.notificaciones.index'))
            ->assertOk()->assertSee('30/11/2026 23:30');
    }

    public function test_el_formulario_de_matricula_propone_la_fecha_de_lima(): void
    {
        $this->actingAs($this->adminUser())->get(route('admin.matriculas.create'))
            ->assertOk()
            ->assertSee('name="fecha_matricula" type="date" value="2026-11-30"', false);
    }
}
