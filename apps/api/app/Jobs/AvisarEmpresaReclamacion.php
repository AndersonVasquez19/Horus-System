<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ReclamacionMail;
use App\Models\Reclamacion;
use App\Services\GestionReclamaciones;
use App\Support\Entrada;
use App\Support\HojaReclamacion;
use App\Support\Reclamaciones;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Aviso a la empresa de una hoja recibida por la web, con «Responder a» = correo del consumidor. Lleva solo el ID
 * (ningún dato personal serializado). No usa `reclamacion_envios`: esa tabla es la constancia de lo remitido al
 * consumidor y admite una sola copia por hoja. Si agota los intentos, avisa en el panel; el caso ya está allí.
 * Un reintento tras un corte puede repetir el aviso: no perjudica a nadie.
 */
class AvisarEmpresaReclamacion implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries;

    public function __construct(public int $reclamacionId)
    {
        $this->tries = Reclamaciones::maxIntentos();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $segundos = config('reclamaciones.correo.retroceso_segundos', [60, 300, 900]);

        return is_array($segundos) ? array_values(array_map(static fn (mixed $valor): int => Entrada::entero($valor), $segundos)) : [60, 300, 900];
    }

    public function handle(): void
    {
        $empresa = Reclamaciones::correoEmpresa();
        $reclamacion = Reclamacion::query()->find($this->reclamacionId);
        if ($empresa === null || $reclamacion === null || ! Reclamaciones::envioHabilitado()) {
            return;
        }
        $texto = "Se registró una hoja en el Libro de Reclamaciones de la web.\n"
            ."Respóndela desde el panel administrativo para que quede registrada.\n\n"
            .HojaReclamacion::cuerpo($reclamacion);
        try {
            Mail::to($empresa)->send(new ReclamacionMail('Nueva: '.HojaReclamacion::asunto($reclamacion), $texto, $reclamacion->email));
        } catch (Throwable) {
            // El mensaje original puede traer direcciones: no debe llegar a `failed_jobs` ni a los registros.
            throw new RuntimeException('Aviso de reclamación a la empresa fallido; se reintentará.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $reclamacion = Reclamacion::query()->find($this->reclamacionId);
        if ($reclamacion !== null) {
            app(GestionReclamaciones::class)->avisarFalloCorreo($reclamacion, 'el aviso a la empresa');
        }
    }
}
