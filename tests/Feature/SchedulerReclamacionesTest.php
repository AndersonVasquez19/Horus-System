<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** Sprint 22, Fase 3: registro de `horus:reclamaciones-vencimientos` en el scheduler (sin ejecutar `schedule:run`). */
class SchedulerReclamacionesTest extends TestCase
{
    private const COMANDO = 'horus:reclamaciones-vencimientos';

    private function evento(string $comando): Event
    {
        $this->artisan('list')->assertSuccessful(); // carga routes/console.php
        $eventos = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $e): bool => str_contains((string) $e->command, $comando),
        ));
        $this->assertCount(1, $eventos, "Debe haber exactamente una tarea programada para {$comando}.");

        return $eventos[0];
    }

    public function test_aparece_en_schedule_list_a_las_ocho_de_lima(): void
    {
        // En la zona de Lima la tarea se ve a las 08:00 ...
        $this->artisan('schedule:list', ['--timezone' => 'America/Lima'])
            ->expectsOutputToContain('0 8 * * *  php artisan '.self::COMANDO)
            ->assertSuccessful();
        // ... y por defecto (zona de la app, UTC) schedule:list la convierte: 08:00 Lima = 13:00 UTC.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('0 13 * * *  php artisan '.self::COMANDO)
            ->assertSuccessful();
    }

    public function test_frecuencia_diaria_a_las_ocho_en_la_zona_de_lima(): void
    {
        $evento = $this->evento(self::COMANDO);

        $this->assertSame('0 8 * * *', $evento->expression);
        $this->assertSame('America/Lima', (string) $evento->timezone);
    }

    public function test_corresponde_a_las_ocho_de_lima_y_no_a_las_ocho_utc(): void
    {
        $evento = $this->evento(self::COMANDO);

        $this->travelTo(Carbon::parse('2026-11-11 08:00:00', 'America/Lima'));
        $this->assertTrue($evento->isDue($this->app), '08:00 Lima');
        $this->travelTo(Carbon::parse('2026-11-11 07:59:00', 'America/Lima'));
        $this->assertFalse($evento->isDue($this->app), '07:59 Lima');
        $this->travelTo(Carbon::parse('2026-11-11 08:01:00', 'America/Lima'));
        $this->assertFalse($evento->isDue($this->app), '08:01 Lima');
        $this->travelTo(Carbon::parse('2026-11-11 08:00:00', 'UTC')); // 03:00 en Lima
        $this->assertFalse($evento->isDue($this->app), '08:00 UTC no es la hora programada');
    }

    public function test_evita_ejecuciones_simultaneas_con_un_bloqueo_que_caduca_pronto(): void
    {
        $evento = $this->evento(self::COMANDO);

        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(30, $evento->expiresAt, 'Un bloqueo abandonado caduca en 30 minutos, no en las 24 h predeterminadas.');
    }

    public function test_no_corre_en_segundo_plano_ni_cambia_el_otro_comando_programado(): void
    {
        $this->assertFalse($this->evento(self::COMANDO)->runInBackground);

        $matriculas = $this->evento('horus:matriculas-notificar');
        $this->assertSame('* * * * *', $matriculas->expression);
        $this->assertFalse($matriculas->withoutOverlapping);
    }
}
