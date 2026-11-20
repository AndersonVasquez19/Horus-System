<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EnvioAvisosExternosService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un registro de `avisos_externos` por la cola sin serializar PII ni secretos.
 */
class EnviarAvisoExterno implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $avisoId) {}

    public function handle(EnvioAvisosExternosService $service): void
    {
        try {
            $service->send($this->avisoId);
        } catch (Throwable) {
            Log::warning('aviso.externo', ['outcome' => 'processing_unavailable']);
        }
    }
}
