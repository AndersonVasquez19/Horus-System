<?php

namespace App\Console\Commands;

use App\Models\MatriculaNotification;
use App\Services\MatriculaNotificationService;
use Illuminate\Console\Command;

class DispatchMatriculaNotifications extends Command
{
    protected $signature = 'horus:matriculas-notificar {--status : Solo muestra conteos, sin despacho} {--limit=100}';

    protected $description = 'Despacha bienvenidas pendientes; nunca envía directamente desde el comando';

    public function handle(MatriculaNotificationService $service): int
    {
        if ($this->option('status')) {
            $counts = MatriculaNotification::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            foreach ($counts as $status => $total) {
                $this->line($status.': '.$total);
            }

            return self::SUCCESS;
        }
        if (! $service->configured()) {
            $this->warn('Despacho desactivado o configuración incompleta: revisar matrícula-mail, Resend, remitente y cola asíncrona.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('El límite debe estar entre 1 y 1000.');

            return self::FAILURE;
        }
        $this->info('Notificaciones encoladas: '.$service->dispatchDue($limit));

        return self::SUCCESS;
    }
}
