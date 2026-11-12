<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las migraciones apartadas en database/migraciones-suspendidas/ no las ejecuta `migrate`, pero las pruebas de
 * las funciones inactivas (verificación de correo, solicitudes de matrícula) necesitan sus tablas. Solo se
 * instalan en SQLite :memory:; nunca contra una base real.
 */
trait InstalaMigracionesSuspendidas
{
    protected function rutaMigracionSuspendida(string $archivo): string
    {
        return database_path('migraciones-suspendidas/'.$archivo.'.php');
    }

    /** @param list<string> $archivos nombres sin extensión, p. ej. 2026_10_28_000022_create_registros_pendientes_table */
    protected function instalarMigracionesSuspendidas(array $archivos): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Las migraciones suspendidas solo se instalan en SQLite :memory:.');
        }

        foreach ($archivos as $archivo) {
            $this->cargarMigracionSuspendida($archivo)->up();
        }
    }

    protected function cargarMigracionSuspendida(string $archivo): object
    {
        return require $this->rutaMigracionSuspendida($archivo);
    }
}
