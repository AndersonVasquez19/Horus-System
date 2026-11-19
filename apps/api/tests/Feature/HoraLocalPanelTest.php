<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

/**
 * Pulido del panel (A): las pantallas muestran hora de Lima y el «mes actual» del inicio es el de Lima.
 * Reloj fijado a las 23:30 de Lima del último día del mes (04:30 UTC del día siguiente). SQLite en memoria.
 */
class HoraLocalPanelTest extends TestCase
{
    use LlamaMatriculasDelPanel;
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

    public function test_la_matricula_y_las_notificaciones_muestran_la_hora_de_lima(): void
    {
        $admin = $this->adminUser();
        $matricula = $this->matricula('2026-11-30');
        NotificacionInterna::create([
            'user_id' => $admin->id, 'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
            'titulo' => 'Aviso de prueba', 'mensaje' => 'x', 'tipo' => 'info',
        ]);

        // La matrícula la sirve el panel: entrega el instante exacto (ISO 8601); mostrarlo en hora de Lima es de la UI de Next.
        $creado = $this->actingAs($admin)->panelMatriculas('GET', (string) $matricula->id)->assertOk()->json('data.creado_en');
        $this->assertTrue(Carbon::parse($creado)->equalTo($matricula->created_at));
        $this->assertSame('2026-11-30 23:30', Carbon::parse($creado)->setTimezone('America/Lima')->format('Y-m-d H:i'));

        // Las notificaciones las sirve el panel con `fecha_local` ya formateada en Lima.
        config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
        $this->actingAs($admin)->json('GET', '/api/panel/v1/compartido/notificaciones', [], ['X-Horus-Web-Token' => config('horus-web.service_token')])
            ->assertOk()->assertJsonPath('data.0.fecha_local', '30/11/2026 23:30');
    }
}
