<?php

namespace App\Observers;

use App\Models\Matricula;
use App\Models\MatriculaNotification;
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

        $notification = new MatriculaNotification;
        $notification->setConnection($matricula->getConnectionName());
        $notification->fill([
            'matricula_id' => $matricula->getKey(),
            'delivery_key' => (string) Str::uuid(),
            'available_at' => now(),
        ])->save();
    }
}
