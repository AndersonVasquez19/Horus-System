<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecordatoriosService;
use Illuminate\Console\Command;

/**
 * Ejecuta la generación diaria de recordatorios operativos (cuotas por vencer, pagos vencidos, sesiones del día y mensajes sin atender).
 * Termina sin efectos cuando `recordatorios.internos_enabled=false`.
 */
class RecordatoriosDiarios extends Command
{
    protected $signature = 'horus:recordatorios-diarios
        {--dry-run : Simula y muestra conteos sin escribir notificaciones ni encolar avisos}
        {--limit=200 : Máximo de registros evaluados por categoría (1 a 1000)}';

    protected $description = 'Genera recordatorios internos (y espejo externo si está habilitado) de cuotas, sesiones y mensajes';

    public function handle(RecordatoriosService $servicio): int
    {
        $limite = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limite === false) {
            $this->error('El límite debe estar entre 1 y 1000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $res = $servicio->ejecutar($dryRun, $limite);

        if ($res['omitido_desactivado'] ?? false) {
            $this->info('Recordatorios desactivados (RECORDATORIOS_INTERNOS_ENABLED=false): no se realizó ninguna escritura.');

            return self::SUCCESS;
        }

        $prefijo = $dryRun ? '[Simulación] ' : '';
        $this->line("{$prefijo}Cuotas por vencer: {$res['cuotas']} · Sesiones hoy: {$res['sesiones']} · Mensajes pendientes: {$res['mensajes']} · Pagos vencidos: {$res['mora']} · Lista de espera con cupo: {$res['espera']} · Avisos externos encolados: {$res['externos']}");

        return self::SUCCESS;
    }
}
