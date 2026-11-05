<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ExportadorCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SincronizarCatalogoAdvisor implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    public int $maxExceptions = 3;

    private ?string $versionPendiente = null;

    private ?int $generacionPendiente = null;

    private int $limite;

    private string $operacion = '';

    public function __construct()
    {
        $this->limite = time() + 300;
        $this->operacion = bin2hex(random_bytes(16));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable)->setTimestamp($this->limite);
    }

    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [5, 30, 90];

    public function handle(ExportadorCatalogoAdvisor $exportador, HorusAdvisorClient $advisor): void
    {
        if (! config('horus-advisor.enabled', false) || ! config('horus-catalog.sync_enabled', false)) {
            return;
        }

        $versionClave = config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version');
        $generacionClave = 'horus-ai.catalog.generation';
        $generacion = (int) Cache::get($generacionClave, 0);
        $pendienteClave = $this->clavePendiente();
        // DatabaseQueue libera el payload original, sin serializar los cambios de handle().
        $pendiente = Cache::get($pendienteClave);
        if (is_array($pendiente) && is_string($pendiente['version'] ?? null)
            && preg_match('/\A[a-f0-9]{40}\z/D', $pendiente['version']) === 1
            && is_int($pendiente['generacion'] ?? null)) {
            $this->versionPendiente = $pendiente['version'];
            $this->generacionPendiente = $pendiente['generacion'];
        }
        if ($this->generacionPendiente !== null && $this->generacionPendiente !== $generacion) {
            Cache::forget($pendienteClave);

            return;
        }
        try {
            if ($this->versionPendiente !== null) {
                $resultado = $advisor->estadoSincronizacionCatalogo($this->versionPendiente);
            } else {
                $catalogo = $exportador->construirParaSincronizacion();
                $resultado = $advisor->sincronizarCatalogo($catalogo);
            }
            if ($resultado === null) {
                $this->versionPendiente = null;
                $this->generacionPendiente = null;
                Cache::forget($pendienteClave);
                throw new RuntimeException('No se confirmó la aplicación del catálogo.');
            }
            // Una publicación posterior invalida esta respuesta, aunque el HTTP anterior haya terminado después.
            if ((int) Cache::get($generacionClave, 0) !== $generacion) {
                Cache::forget($pendienteClave);

                return;
            }
            if ($resultado['catalog'] === 'pending') {
                $this->versionPendiente = $resultado['catalog_version'];
                $this->generacionPendiente = $generacion;
                // Una cola real reconsulta; el driver sync no ejecuta liberaciones diferidas.
                if ($this->job === null || $this->job instanceof SyncJob || time() >= $this->limite) {
                    throw new RuntimeException('La reconstrucción requiere confirmación mediante cola.');
                }
                Cache::put($pendienteClave, ['version' => $this->versionPendiente,
                    'generacion' => $this->generacionPendiente], $this->retryUntil());
                $this->release(5);

                return;
            }
            Cache::forever($versionClave, $resultado['catalog_version']);
            Cache::forget($pendienteClave);
            Log::info('advisor_catalog.sync', ['outcome' => 'loaded', 'version' => $resultado['catalog_version']]);
        } catch (Throwable $error) {
            Log::warning('advisor_catalog.sync', ['outcome' => 'failed', 'error' => $error instanceof RuntimeException ? 'sync_rejected' : 'sync_unavailable']);
            throw new RuntimeException('La sincronización del catálogo no se confirmó.');
        }
    }

    private function clavePendiente(): string
    {
        // UUID estable incluso para jobs previos a la incorporación de operacion.
        return 'horus-ai.catalog.pending.'.hash('sha256', $this->job?->uuid() ?: $this->operacion);
    }

    public function failed(?Throwable $error): void
    {
        Cache::forget($this->clavePendiente());
    }
}
