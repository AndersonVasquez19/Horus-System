<?php

namespace Tests\Feature;

use App\Models\Estudiante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\LlamaEstudiantesDelPanel;
use Tests\TestCase;

/**
 * La fecha de nacimiento no puede ser futura según el día de Lima, no el de UTC: la aplicación calcula en UTC
 * y desde las 19:00 de Lima ya es «mañana» en UTC.
 */
class EstudianteFechaNacimientoLimaTest extends TestCase
{
    use LlamaEstudiantesDelPanel;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function datos(string $fecha, string $dni = '70123456'): array
    {
        return [
            'dni' => $dni,
            'nombres' => 'Lucía',
            'apellidos' => 'Pérez Soto',
            'fecha_nacimiento' => $fecha,
            'estado' => 'activo',
        ];
    }

    public function test_store_rejects_tomorrow_in_lima_even_when_utc_already_is_tomorrow(): void
    {
        // 20:00 del 9/11 en Lima = 01:00 UTC del 10/11.
        Carbon::setTestNow('2026-11-10 01:00:00 UTC');

        $this->actingAs($this->adminUser());
        $this->panelEstudiantes('POST', '', $this->datos('2026-11-10'))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');

        $this->assertDatabaseCount('estudiantes', 0);

        $this->panelEstudiantes('POST', '', $this->datos('2026-11-09'))->assertCreated();

        $this->assertDatabaseHas('estudiantes', ['dni' => '70123456']);
    }

    public function test_store_accepts_today_in_lima_at_midday(): void
    {
        Carbon::setTestNow('2026-11-10 17:00:00 UTC');

        $this->actingAs($this->adminUser());
        $this->panelEstudiantes('POST', '', $this->datos('2026-11-10'))->assertCreated();

        $this->panelEstudiantes('POST', '', $this->datos('2026-11-11', '70123457'))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');
    }

    public function test_update_rejects_tomorrow_in_lima_even_when_utc_already_is_tomorrow(): void
    {
        Carbon::setTestNow('2026-11-10 01:00:00 UTC');

        $estudiante = Estudiante::create([
            'dni' => '70123456',
            'nombres' => 'Lucía',
            'apellidos' => 'Pérez Soto',
            'estado' => 'activo',
        ]);

        $this->actingAs($this->adminUser());
        $this->panelEstudiantes('PUT', (string) $estudiante->id, $this->datos('2026-11-10'))
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_nacimiento');

        $this->panelEstudiantes('PUT', (string) $estudiante->id, $this->datos('2026-11-09'))->assertOk();

        $this->assertSame('2026-11-09', $estudiante->fresh()->fecha_nacimiento->toDateString());
    }
}
