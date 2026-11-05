<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicacionCatalogoAdvisorObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        if ($model instanceof Curso && ! $model->wasChanged(['estado', 'deleted_at'])) {
            return;
        }
        if ($model instanceof ServicioTecnologico && ! $model->wasChanged('estado')) {
            return;
        }
        $this->solicitarSincronizacion();
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Curso || $model instanceof CursoPublicacion
            || $model instanceof ServicioTecnologico || $model instanceof ServicioPublicacion) {
            $this->solicitarSincronizacion();
        }
    }

    private function solicitarSincronizacion(): void
    {
        if (! config('horus-advisor.enabled', false) || ! config('horus-catalog.sync_enabled', false)) {
            return;
        }

        Cache::add('horus-ai.catalog.generation', 0);
        Cache::increment('horus-ai.catalog.generation');
        Cache::forget(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version'));
        try {
            SincronizarCatalogoAdvisor::dispatch()->afterCommit();
        } catch (Throwable) {
            // La publicación ya confirmó; sin versión conocida, Laravel usa contingencia y no acepta el snapshot previo.
            Log::warning('advisor_catalog.sync_dispatch_failed');
        }
    }
}
