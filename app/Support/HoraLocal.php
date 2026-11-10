<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hora de presentación del panel. La aplicación guarda y calcula en UTC (`config/app.php`), pero el personal
 * trabaja en Lima: toda fecha u hora que se muestra, y todo «hoy» o «mes actual», se expresa en esta zona.
 *
 * No convertir aquí columnas `date` (fecha_matricula, fecha_nacimiento, vigencias): no tienen hora ni zona.
 */
final class HoraLocal
{
    public const ZONA = 'America/Lima';

    public static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA);
    }

    /** Fecha de hoy en Lima, AAAA-MM-DD. */
    public static function hoy(): string
    {
        return self::ahora()->toDateString();
    }

    /** Formatea un instante en hora de Lima sin modificar el objeto recibido. `—` si no hay valor. */
    public static function formato(?CarbonInterface $instante, string $formato = 'd/m/Y H:i'): string
    {
        if ($instante === null) {
            return '—';
        }

        return CarbonImmutable::instance($instante)->setTimezone(self::ZONA)->format($formato);
    }
}
