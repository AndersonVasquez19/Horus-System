<?php

declare(strict_types=1);

namespace Tests\Unit;

use Horus\Local\LocalCatalogRenewal;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/scripts/Support/LocalCatalogRenewal.php';

class LocalCatalogRenewalTest extends TestCase
{
    public function test_timeout_real_del_control_no_cierra_un_proceso_sano_ni_reintenta(): void
    {
        $healthy = new Process([PHP_BINARY, '-r', 'sleep(10);']);
        $healthy->start();
        $attempts = 0;
        $events = [];
        try {
            $this->assertTrue($healthy->isRunning());
            $result = LocalCatalogRenewal::attempt(function () use (&$attempts): void {
                $attempts++;
                $control = new Process([PHP_BINARY, '-r', 'usleep(750000);']);
                $control->setTimeout(0.15);
                $control->run();
            }, function (array $event) use (&$events): void {
                $events[] = $event;
            });
            $this->assertFalse($result);
            $this->assertSame(1, $attempts);
            $this->assertTrue($healthy->isRunning());
            $this->assertCount(1, $events);
            $this->assertSame('renewal_control_unconfirmed', $events[0]['event']);
            $this->assertSame(ProcessTimedOutException::class, $events[0]['exception_type']);
            $this->assertArrayNotHasKey('message', $events[0]);
        } finally {
            $healthy->stop(1);
        }
    }

    public function test_control_correcto_se_ejecuta_una_vez_sin_registrar_fallo(): void
    {
        $calls = 0;
        $this->assertTrue(LocalCatalogRenewal::attempt(function () use (&$calls): void {
            $calls++;
        }, fn () => $this->fail('Un éxito no registra un fallo.')));
        $this->assertSame(1, $calls);
    }

    public function test_fallo_del_registro_tampoco_propaga_excepciones_privadas(): void
    {
        $this->assertFalse(LocalCatalogRenewal::attempt(static function (): never {
            throw new RuntimeException('Dato sintético que no debe registrarse.');
        }, static function (array $event): never {
            throw new RuntimeException('Registro no disponible.');
        }));
    }
}
