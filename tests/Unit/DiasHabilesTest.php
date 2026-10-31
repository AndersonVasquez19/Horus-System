<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DiasHabiles;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DiasHabilesTest extends TestCase
{
    private function d(string $fecha, string $zona = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, $zona);
    }

    public function test_suma_dias_habiles_saltando_fines_de_semana(): void
    {
        $this->assertSame('2026-10-27', DiasHabiles::sumar($this->d('2026-10-26'), 1, [])->toDateString());
        $this->assertSame('2026-11-02', DiasHabiles::sumar($this->d('2026-10-26'), 5, [])->toDateString());
        $this->assertSame('2026-11-16', DiasHabiles::sumar($this->d('2026-10-26'), 15, [])->toDateString());
        $this->assertSame('2026-10-26', DiasHabiles::sumar($this->d('2026-10-24'), 1, [])->toDateString(), 'Desde sábado: el primer hábil es el lunes');
        $this->assertSame('2026-10-24', DiasHabiles::sumar($this->d('2026-10-24'), 0, [])->toDateString());
    }

    public function test_los_feriados_configurados_no_cuentan(): void
    {
        $this->assertSame('2026-11-03', DiasHabiles::sumar($this->d('2026-10-26'), 5, ['2026-10-28'])->toDateString());
    }

    public function test_entre_cuenta_dias_habiles_independientemente_de_la_zona_horaria(): void
    {
        $this->assertSame(3, DiasHabiles::entre($this->d('2026-10-26'), $this->d('2026-10-29'), []));
        $this->assertSame(3, DiasHabiles::entre($this->d('2026-10-26'), $this->d('2026-10-29', 'America/Lima'), []));
        $this->assertSame(5, DiasHabiles::entre($this->d('2026-10-26'), $this->d('2026-11-02'), []));
        $this->assertSame(0, DiasHabiles::entre($this->d('2026-10-29'), $this->d('2026-10-26'), []));
        $this->assertSame(0, DiasHabiles::entre($this->d('2026-10-26'), $this->d('2026-10-26'), []));
        $this->assertSame(2, DiasHabiles::entre($this->d('2026-10-26'), $this->d('2026-10-29'), ['2026-10-27']));
    }
}
