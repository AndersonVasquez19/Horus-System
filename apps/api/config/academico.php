<?php

declare(strict_types=1);

return [
    /* Tope de cupos de un curso o de una edición: los grupos son de hasta 10 personas. */
    'cupos_maximos' => 10,

    /* Si es true, un certificado exige que la edición esté en estado `cerrada` (apagado por defecto: no cambia el comportamiento previo). */
    'certificado_exige_acta_cerrada' => filter_var(env('HORUS_CERTIFICADO_EXIGE_ACTA', false), FILTER_VALIDATE_BOOLEAN),
];
