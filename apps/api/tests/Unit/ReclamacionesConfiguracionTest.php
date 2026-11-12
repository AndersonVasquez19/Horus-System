<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ReclamacionesConfiguracionTest extends TestCase
{
    /** @return array<string, array{string|false, string|false, bool, bool}> */
    public static function activaciones(): array
    {
        return [
            'instalacion sin activacion' => [false, false, false, false],
            'solo recepcion' => ['true', false, true, false],
            'solo correo' => [false, 'true', false, true],
            'recepcion y correo' => ['true', 'true', true, true],
            'desactivacion explicita' => ['false', 'false', false, false],
        ];
    }

    #[DataProvider('activaciones')]
    public function test_activacion_por_entorno_con_predeterminados_apagados(
        string|false $recepcion,
        string|false $correo,
        bool $recepcionEsperada,
        bool $correoEsperado,
    ): void {
        // Sin arrancar Laravel, cargar .env, consultar bases ni enviar correo.
        $proceso = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$config = require 'config/reclamaciones.php';
echo json_encode([$config['enabled'], $config['correo']['enabled']], JSON_THROW_ON_ERROR);
PHP], dirname(__DIR__, 2), [
            'RECLAMACIONES_ENABLED' => $recepcion,
            'RECLAMACIONES_CORREO_ENABLED' => $correo,
        ]);
        $proceso->mustRun();

        $this->assertSame(
            [$recepcionEsperada, $correoEsperado],
            json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
