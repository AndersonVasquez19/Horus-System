<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CorreoCertificados;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Envía el correo de un certificado por la cola. Lleva solo el ID: ningún dato personal se serializa. */
class EnviarCertificadoCorreo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 4;

    public function __construct(public int $certificadoId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CorreoCertificados $servicio): void
    {
        $servicio->enviar($this->certificadoId);
    }

    public function failed(Throwable $e): void
    {
        // Ni el mensaje ni la traza: pueden contener la dirección del estudiante o credenciales del proveedor.
        app(CorreoCertificados::class)->marcarFallo($this->certificadoId);
    }
}
