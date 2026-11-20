<?php

namespace App\Jobs;

use App\Services\MatriculaNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMatriculaWelcome implements ShouldQueue
{
    use Queueable;

    // La bandeja controla los reintentos; queue:retry tampoco evita sus límites.
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $notificationId) {}

    public function handle(MatriculaNotificationService $service): void
    {
        try {
            $service->send($this->notificationId);
        } catch (Throwable) {
            // La reserva caduca y el scheduler recupera el trabajo. No serializar excepciones con PII.
            Log::warning('matricula.mail', ['outcome' => 'processing_unavailable']);
        }
    }
}
