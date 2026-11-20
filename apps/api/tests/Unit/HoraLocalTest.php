<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class HoraLocalTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_formatea_en_hora_de_lima_sin_modificar_el_original(): void
    {
        $utc = Carbon::parse('2026-12-01 04:30:00', 'UTC');

        $this->assertSame('30/11/2026 23:30', HoraLocal::formato($utc));
        $this->assertSame('30/11/2026', HoraLocal::formato($utc, 'd/m/Y'));
        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('2026-12-01 04:30:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_sin_valor_devuelve_guion(): void
    {
        $this->assertSame('—', HoraLocal::formato(null));
    }

    public function test_hoy_es_el_dia_de_lima_aunque_en_utc_ya_sea_el_siguiente(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 04:30:00', 'UTC'));

        $this->assertSame('2026-11-30', HoraLocal::hoy());
        $this->assertSame('America/Lima', HoraLocal::ahora()->getTimezone()->getName());
    }
}
