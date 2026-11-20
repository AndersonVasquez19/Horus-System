<?php

namespace App\Observers;

use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Services\MatriculaNotificationService;
use Illuminate\Support\Str;

class MatriculaNotificationObserver
{
    // Intencionalmente síncrono: la intención de envío revierte junto con la matrícula.
    // Nunca realiza HTTP ni despacha una cola desde la transacción.
    public function created(Matricula $matricula): void
    {
        if (! in_array($matricula->estado, ['pendiente', 'pagada'], true)) {
            return;
        }

        // Solo lee configuración (sin consultas ni HTTP): con el envío apagado la matrícula se registra
        // igual y su aviso queda retenido de forma persistente, sin despacho al activar después.
        $notification = new MatriculaNotification;
        $notification->setConnection($matricula->getConnectionName());
        $notification->fill([
            'matricula_id' => $matricula->getKey(),
            'delivery_key' => (string) Str::uuid(),
            'available_at' => now(),
            ...app(MatriculaNotificationService::class)->initialState(),
        ])->save();
    }
}
