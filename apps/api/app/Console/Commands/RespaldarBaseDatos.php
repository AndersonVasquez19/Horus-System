<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RespaldoLocal;
use Illuminate\Console\Command;
use Throwable;

/**
 * Crea un respaldo local de PostgreSQL (`pg_dump`) y aplica la retención. Con `--simular` no escribe nada: solo informa qué
 * haría. Nunca imprime credenciales ni la salida de `pg_dump`. No restaura (ver docs/runbook-respaldo-local.md).
 */
class RespaldarBaseDatos extends Command
{
    protected $signature = 'horus:respaldo
        {--simular : No crea ni borra nada; muestra la copia que se crearía y las que se podarían}
        {--solo-podar : Aplica solo la retención, sin crear una copia nueva}';

    protected $description = 'Respaldo local de PostgreSQL con pg_dump, suma SHA-256 y retención configurable';

    public function handle(RespaldoLocal $respaldo): int
    {
        $simular = (bool) $this->option('simular');

        try {
            if (! $this->option('solo-podar')) {
                if ($simular) {
                    $this->line('[Simulación] Se crearía una copia nueva con pg_dump en el disco privado de respaldos.');
                } else {
                    $copia = $respaldo->crear();
                    $this->info("Copia creada: {$copia['nombre']} ({$copia['bytes']} bytes, sha256 {$copia['sha256']}).");
                }
            }

            $podadas = $respaldo->podar($simular);
            $prefijo = $simular ? '[Simulación] Se borrarían' : 'Borradas por retención:';
            $this->line($podadas === [] ? 'Retención: no hay copias que podar.' : $prefijo.' '.implode(', ', $podadas));
        } catch (Throwable $e) {
            // Solo el mensaje propio del servicio (sin salida de pg_dump, usuario ni host).
            $this->error('El respaldo falló: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
