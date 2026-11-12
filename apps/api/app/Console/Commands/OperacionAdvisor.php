<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Services\OperacionHorus;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Throwable;

class OperacionAdvisor extends Command
{
    private bool $workerActivo = false;

    protected $signature = 'horus:advisor-operacion {action=status : status, worker o sync}
        {--probe : Lee heartbeat/cache y consulta health/ready locales}
        {--queue : Con probe, cuenta cola y fallidos (acceso explícito a su base)}
        {--execute : Autoriza ejecutar worker o despachar sincronización}';

    protected $description = 'Preflight y operación opt-in de Horus AI; no habilita el asistente público';

    public function handle(OperacionHorus $ops): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['status', 'worker', 'sync'], true) || ($this->option('queue') && ! $this->option('probe'))) {
            $this->error('invalid_options');

            return self::FAILURE;
        }
        if ($action === 'status' || ! $this->option('execute')) {
            $status = $ops->estado((bool) $this->option('probe'), (bool) $this->option('queue'));
            $this->line((string) json_encode($status, JSON_UNESCAPED_SLASHES));

            return $status['errors'] !== [] || ($this->option('probe') && ! $status['ready']) ? self::FAILURE : self::SUCCESS;
        }
        if (! config('horus-ops.enabled') || $ops->errores() !== []) {
            $this->error('operation_disabled_or_invalid');

            return self::FAILURE;
        }
        try {
            if ($action === 'sync') {
                // El job existente conserva generación, firma, idempotencia y confirmación diferida.
                $lock = Cache::lock('horus.ops.renew', 110);
                if (! $lock->get()) {
                    $this->line('renewal_already_dispatched');

                    return self::SUCCESS;
                }
                try {
                    SincronizarCatalogoAdvisor::dispatch()->afterCommit();
                } catch (Throwable) {
                    $lock->release();
                    throw new \RuntimeException('dispatch_failed');
                }
                // TTL agrupa tick/reintentos del scheduler; no elimina la versión válida.
                $this->line('catalog_dispatched');

                return self::SUCCESS;
            }

            return $this->worker();
        } catch (Throwable) {
            $this->error('operation_failed');

            return self::FAILURE;
        }
    }

    private function worker(): int
    {
        $lock = Cache::lock('horus.ops.worker.lock', 3700);
        if (! $lock->get()) {
            $this->error('worker_already_running');

            return self::FAILURE;
        }
        $connection = (string) config('queue.default');
        $beat = ['at' => 0, 'attempts' => 0, 'failures' => 0];
        $this->workerActivo = true;
        $pulse = function (Looping|JobProcessed|JobFailed $event) use (&$beat, $connection): void {
            if (! $this->workerActivo || $event->connectionName !== $connection) {
                return;
            }
            if ($event instanceof Looping && $beat['at'] > time() - 15) {
                return;
            }
            $beat['at'] = time();
            if ($event instanceof JobProcessed) {
                $beat['attempts']++;
            }
            if ($event instanceof JobFailed) {
                $beat['failures']++;
            }
            Cache::put('horus.ops.worker', $beat, 90);
        };
        $events = [Looping::class, JobProcessed::class,
            JobFailed::class];
        foreach ($events as $event) {
            Event::listen($event, $pulse);
        }
        try {
            return $this->call('queue:work', ['connection' => $connection, '--sleep' => 3, '--tries' => 3,
                '--timeout' => 45, '--max-time' => 3600, '--memory' => 256, '--quiet' => true]);
        } finally {
            $this->workerActivo = false;
            Cache::forget('horus.ops.worker');
            $lock->release();
            // No se retiran listeners ajenos del dispatcher compartido.
        }
    }
}
