<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numeración correlativa anual `NNNNNNNNN-AAAA` (Anexo I). Debe invocarse dentro de la transacción que
 * inserta la reclamación: si esa transacción revierte, el incremento también, de modo que no quedan huecos.
 *
 * Concurrencia (PostgreSQL, `READ COMMITTED`): `INSERT … ON CONFLICT DO NOTHING` crea la fila del año sin
 * carrera (una segunda transacción espera a la primera en el índice único); `SELECT … FOR UPDATE` serializa
 * las altas del mismo año hasta el COMMIT/ROLLBACK y, tras esperar, relee la versión confirmada. Años
 * distintos usan filas distintas y no se bloquean. Bajo `REPEATABLE READ`/`SERIALIZABLE` la espera terminaría
 * en 40001 (sin duplicados, pero el alta fallaría): la aplicación usa el aislamiento por defecto del servidor.
 * SQLite ignora el bloqueo y lo da la propia transacción, por lo que la concurrencia real solo se mide en
 * `tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest`.
 *
 * `$conexion` solo existe para que esa prueba ejecute este mismo código en una segunda sesión; por defecto
 * se usa la conexión predeterminada.
 */
class NumeradorReclamaciones
{
    public const MAXIMO = 999_999_999;

    public function siguiente(int $anio, ?ConnectionInterface $conexion = null): string
    {
        $db = $conexion ?? DB::connection();
        if ($db->transactionLevel() === 0) {
            throw new LogicException('La numeración exige una transacción abierta.');
        }
        if ($anio < 2000 || $anio > 9999) {
            throw new LogicException('Año de numeración fuera de rango.');
        }

        $db->table('reclamacion_contadores')->insertOrIgnore(['anio' => $anio, 'ultimo' => 0]);
        $actual = (int) $db->table('reclamacion_contadores')->where('anio', $anio)->lockForUpdate()->value('ultimo');
        if ($actual >= self::MAXIMO) {
            throw new LogicException('Se agotó la numeración del año.');
        }
        $db->table('reclamacion_contadores')->where('anio', $anio)->update(['ultimo' => $actual + 1]);

        return sprintf('%09d-%04d', $actual + 1, $anio);
    }
}
