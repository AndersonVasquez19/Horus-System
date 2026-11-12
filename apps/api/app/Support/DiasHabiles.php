<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Cálculo de días hábiles: lunes a viernes salvo la lista de feriados de config('reclamaciones.feriados').
 * La definición legal de «día hábil» no está verificada (Fase 1.2, decisión E5); esto es una convención configurable.
 */
final class DiasHabiles
{
    /** @param list<string>|null $feriados Fechas 'AAAA-MM-DD'; null usa la configuración. */
    public static function esHabil(CarbonImmutable $fecha, ?array $feriados = null): bool
    {
        $feriados ??= self::feriados();

        return ! $fecha->isWeekend() && ! in_array($fecha->toDateString(), $feriados, true);
    }

    /**
     * Fecha resultante de sumar $dias días hábiles (el día de partida no cuenta).
     *
     * @param  list<string>|null  $feriados
     */
    public static function sumar(CarbonImmutable $desde, int $dias, ?array $feriados = null): CarbonImmutable
    {
        $fecha = $desde->startOfDay();
        $restantes = max(0, $dias);
        while ($restantes > 0) {
            $fecha = $fecha->addDay();
            if (self::esHabil($fecha, $feriados)) {
                $restantes--;
            }
        }

        return $fecha;
    }

    /**
     * Días hábiles transcurridos entre dos fechas (excluye $desde, incluye $hasta); 0 si $hasta <= $desde.
     *
     * @param  list<string>|null  $feriados
     */
    public static function entre(CarbonImmutable $desde, CarbonImmutable $hasta, ?array $feriados = null): int
    {
        // Se compara por fecha de calendario: los extremos pueden venir en zonas horarias distintas (Lima/UTC).
        $cursor = $desde->startOfDay();
        $fin = $hasta->toDateString();
        $total = 0;
        while ($cursor->toDateString() < $fin) {
            $cursor = $cursor->addDay();
            if (self::esHabil($cursor, $feriados)) {
                $total++;
            }
        }

        return $total;
    }

    /** @return list<string> */
    private static function feriados(): array
    {
        $lista = config('reclamaciones.feriados', []);

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }
}
