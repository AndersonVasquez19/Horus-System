<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Códigos `PREFIJO-AAAA-NNNN` (año de Lima) tomados de `secuencias_codigo` con la fila del contador bloqueada hasta el
 * final de la transacción que los usa: dos altas simultáneas nunca reciben el mismo número y un número jamás se reutiliza.
 * Debe llamarse dentro de `DB::transaction`.
 */
final class CodigoSecuencial
{
    public static function siguiente(string $tipo): string
    {
        $anio = (int) CarbonImmutable::now('America/Lima')->format('Y');
        DB::table('secuencias_codigo')->insertOrIgnore(['tipo' => $tipo, 'anio' => $anio, 'ultimo' => 0]);
        $fila = DB::table('secuencias_codigo')->where('tipo', $tipo)->where('anio', $anio)->lockForUpdate()->first();
        if ($fila === null) {
            throw new \RuntimeException("No se pudo reservar la secuencia de códigos «{$tipo}» del año {$anio}.");
        }
        $numero = Entrada::entero($fila->ultimo) + 1;
        DB::table('secuencias_codigo')->where('id', $fila->id)->update(['ultimo' => $numero]);

        return sprintf('%s-%d-%04d', $tipo, $anio, $numero);
    }
}
