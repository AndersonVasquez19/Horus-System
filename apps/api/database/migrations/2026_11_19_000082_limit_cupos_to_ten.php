<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el tope de cupos (`config('academico.cupos_maximos')`, 10) a los cursos y ediciones que ya existen con más.
 *
 * No toca matrículas: los cupos ocupados son `cupos_maximos - cupos_disponibles` y se conservan. El nuevo máximo es el mayor entre
 * el tope y lo ocupado, de modo que ninguna matrícula vigente queda sin cupo; `cupos_disponibles` se recalcula como máximo menos
 * ocupados (nunca negativo ni superior al máximo, por lo que se cumplen las restricciones de integridad). Solo cambia filas con
 * más cupos que el tope, de modo que repetirla no cambia nada. En una sola sentencia por tabla: todas las expresiones leen los
 * valores previos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tope = (int) config('academico.cupos_maximos', 10);

        foreach (['cursos', 'convocatorias'] as $tabla) {
            DB::update(
                "update {$tabla} set "
                .'cupos_disponibles = (case when (cupos_maximos - cupos_disponibles) > ? then (cupos_maximos - cupos_disponibles) else ? end) - (cupos_maximos - cupos_disponibles), '
                .'cupos_maximos = (case when (cupos_maximos - cupos_disponibles) > ? then (cupos_maximos - cupos_disponibles) else ? end) '
                .'where cupos_maximos > ?',
                [$tope, $tope, $tope, $tope, $tope],
            );
        }
    }

    public function down(): void
    {
        // No restaura los cupos anteriores a propósito: no se conservan. Revertir no debe fallar.
    }
};
