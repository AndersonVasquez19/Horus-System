<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EnvioReclamaciones;
use App\Support\Reclamaciones;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía un registro de `reclamacion_envios` por la cola. Lleva solo el ID (ningún dato personal serializado).
 * El servicio controla el contador de intentos en la base (reserva atómica); el job reintenta con retroceso
 * cuando el servicio lanza una excepción genérica de fallo transitorio.
 */
class EnviarReclamacionCorreo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** Laravel lee la propiedad (no un método): se fija con el máximo configurado. */
    public int $tries;

    public function __construct(public int $envioId)
    {
        $this->tries = Reclamaciones::maxIntentos();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $segundos = config('reclamaciones.correo.retroceso_segundos', [60, 300, 900]);

        return is_array($segundos) ? array_values(array_map('intval', $segundos)) : [60, 300, 900];
    }

    public function handle(EnvioReclamaciones $servicio): void
    {
        $servicio->enviar($this->envioId);
    }
}
