<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reutiliza regresiones existentes sin invocar migrate:fresh ni migraciones suspendidas. */
trait InstalaMigracionesEnMemoria
{
    protected function usingInMemoryDatabases()
    {
        // Cada aplicación conserva su PDO nuevo; no se restaura el PDO de otra prueba.
        return false;
    }

    protected function refreshTestDatabase()
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Este instalador solo acepta SQLite :memory:.');
        }
        $manifiesto = json_decode(file_get_contents(base_path('docs/consolidacion-fase-4-manifiesto.json')), true, 512, JSON_THROW_ON_ERROR);
        $paths = [];
        foreach ($manifiesto['migrations'] as $migracion) {
            if (preg_match('/_00002[23]_/', $migracion['file'])) {
                continue;
            }
            $path = base_path($migracion['file']);
            if (! hash_equals($migracion['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Migración distinta del manifiesto revisado.');
            }
            $paths[] = $path;
        }
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
        $this->beginDatabaseTransaction();
    }
}
