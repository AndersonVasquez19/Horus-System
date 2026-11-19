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
    /**
     * Migraciones con claves foráneas que se revierten en cadena, en el orden en que se aplican. Las del CRM comercial (000044 a
     * 000053) ya no figuran: la 000079 elimina esas tablas.
     */
    private const CADENA_CRM = [
        '2026_11_15_000056_create_convocatorias_tables',
        '2026_11_15_000057_add_convocatoria_id_to_matriculas',
        '2026_11_15_000059_create_evaluaciones_calificaciones_tables',
        '2026_11_15_000060_add_nota_final_to_matriculas',
        // Horus Academic: dependen de convocatorias, matrículas, evaluaciones o sesiones (en PostgreSQL impiden eliminarlas).
        '2026_11_16_000063_add_infraestructura_to_convocatorias',
        '2026_11_16_000066_create_docentes_tables',
        '2026_11_16_000068_create_sesiones_clase_table',
        '2026_11_17_000070_create_asistencias_table',
        '2026_11_17_000072_create_finanzas_tables',
        '2026_11_17_000074_add_evaluaciones_peso_total_guard',
        '2026_11_17_000075_create_comprobantes_pago_table',
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
