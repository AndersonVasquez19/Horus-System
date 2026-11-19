<?php

declare(strict_types=1);

return [
    /*
     * Porcentaje mínimo de asistencia (sobre las sesiones realizadas, sin contar faltas justificadas) para considerar adecuada la asistencia de una edición.
     * Entero de 1 a 100; un valor fuera de rango o no numérico se ignora y se usa 80.
     */
    'asistencia_minima' => (static function (): int {
        $valor = filter_var(env('HORUS_ASISTENCIA_MINIMA', 80), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        return $valor === false ? 80 : $valor;
    })(),
];
