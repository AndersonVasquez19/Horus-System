<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EnvioAvisosExternosService;
use Illuminate\Console\Command;

/**
 * Despacha a la cola los avisos externos pendientes (`avisos_externos`) cuando el canal correspondiente está activo.
 */
class DespacharAvisosExternos extends Command
{
    protected $signature = 'horus:avisos-despachar {--limit=100 : Máximo de avisos reservados por ejecución}';

    protected $description = 'Encola avisos externos pendientes de correo o WhatsApp Cloud cuando sus canales están habilitados';

    public function handle(EnvioAvisosExternosService $servicio): int
    {
        $limite = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limite === false) {
            $this->error('El límite debe estar entre 1 y 500.');

            return self::FAILURE;
        }

        $despachados = $servicio->dispatchDue($limite);
        if ($despachados > 0) {
            $this->info("Avisos externos despachados: {$despachados}.");
        }

        return self::SUCCESS;
    }
}
