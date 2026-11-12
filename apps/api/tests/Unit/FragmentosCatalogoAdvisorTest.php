<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FragmentosCatalogoAdvisor;
use PHPUnit\Framework\TestCase;

class FragmentosCatalogoAdvisorTest extends TestCase
{
    public function test_preserves_short_unicode_and_bounds_long_public_text(): void
    {
        $this->assertSame(['Información pública con tildes y 漢字.'], FragmentosCatalogoAdvisor::textos("Información pública\n\ncon tildes y 漢字."));
        $texto = str_repeat('Configuración de redes públicas y conectividad. ', 30);
        $partes = FragmentosCatalogoAdvisor::textos($texto);
        $this->assertSame(trim($texto), implode(' ', $partes));
        $this->assertTrue(max(array_map(mb_strlen(...), $partes)) <= 600);
        $this->assertSame([600, 300], array_map(mb_strlen(...), FragmentosCatalogoAdvisor::textos(str_repeat('á', 900))));
        $this->assertCount(2, FragmentosCatalogoAdvisor::textos(str_repeat("Redes.\n\n", 100)));
    }
}
