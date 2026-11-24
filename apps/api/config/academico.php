<?php

declare(strict_types=1);

return [
    /* Tope de cupos de un curso o de una edición: los grupos son de hasta 10 personas. */
    'cupos_maximos' => 10,

    /*
     * Porcentaje mínimo de asistencia (sobre las sesiones realizadas, sin contar faltas justificadas) para considerar adecuada la asistencia de una edición.
     * Entero de 1 a 100; un valor fuera de rango o no numérico se ignora y se usa 80.
     */
    'asistencia_minima' => (static function (): int {
        $valor = filter_var(env('HORUS_ASISTENCIA_MINIMA', 80), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        return $valor === false ? 80 : $valor;
    })(),

    /* Nota final mínima aprobatoria para emitir certificado sin excepción (escala 0–20). */
    'nota_minima' => (static function (): float {
        $valor = filter_var(env('HORUS_NOTA_MINIMA', 11), FILTER_VALIDATE_FLOAT);

        return $valor === false || $valor < 0 || $valor > 20 ? 11.0 : (float) $valor;
    })(),

    /* Si es true, un certificado exige que la edición tenga el acta cerrada (apagado por defecto: no cambia el comportamiento previo). */
    'certificado_exige_acta_cerrada' => filter_var(env('HORUS_CERTIFICADO_EXIGE_ACTA', false), FILTER_VALIDATE_BOOLEAN),
];
