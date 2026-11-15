<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Prueba del `down()` de una migración del CRM (Ola 3) sin que las tablas dependientes lo impidan.
 *
 * En PostgreSQL no se puede eliminar una tabla mientras otra tenga una clave foránea hacia ella (Laravel revierte en orden
 * inverso, así que en la práctica nunca ocurre). Aquí se revierten primero las migraciones posteriores de la cadena, se
 * comprueba el estado y se vuelven a aplicar en su orden. En SQLite las claves no se exigen y basta la migración probada.
 */
trait RevierteCadenaCrm
{
    /** Migraciones del CRM que crean tablas o columnas con claves foráneas, en el orden en que se aplican. */
    private const CADENA_CRM = [
        '2026_11_15_000044_create_clientes_tables',
        '2026_11_15_000045_add_cliente_id_to_solicitudes_cotizacion',
        '2026_11_15_000047_create_prospectos_table',
        '2026_11_15_000049_create_oportunidades_tables',
        '2026_11_15_000051_create_proyectos_tables',
        '2026_11_15_000053_create_tickets_soporte_tables',
        '2026_11_15_000056_create_convocatorias_tables',
        '2026_11_15_000057_add_convocatoria_id_to_matriculas',
    ];

    /**
     * Revierte `$migracion` (y, en PostgreSQL, todas las posteriores de la cadena), ejecuta `$comprobar` con ellas revertidas y
     * las vuelve a aplicar.
     *
     * @param  callable(): void  $comprobar
     */
    private function revertirYRestaurar(string $migracion, callable $comprobar): void
    {
        $desde = array_search($migracion, self::CADENA_CRM, true);
        $this->assertNotFalse($desde, 'La migración no figura en la cadena del CRM.');

        $afectadas = DB::getDriverName() === 'pgsql' ? array_slice(self::CADENA_CRM, (int) $desde) : [$migracion];
        $cargar = fn (string $nombre): object => require database_path("migrations/{$nombre}.php");

        foreach (array_reverse($afectadas) as $nombre) {
            $cargar($nombre)->down();
        }
        $comprobar();
        foreach ($afectadas as $nombre) {
            $cargar($nombre)->up();
        }
    }
}
