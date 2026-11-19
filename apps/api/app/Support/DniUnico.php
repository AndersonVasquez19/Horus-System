<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Docente;
use App\Models\Estudiante;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un mismo DNI no puede estar a la vez en `estudiantes` y en `docentes` (decisión del propietario, 16/11/2026): son una sola
 * persona y no se duplica. Cada tabla ya tiene su índice único; esta clase cubre el cruce entre las dos, que una restricción
 * de base de datos no puede expresar.
 *
 * Se debe llamar DENTRO de la transacción que escribe el DNI. En PostgreSQL toma un bloqueo consultivo por DNI hasta el final
 * de la transacción, de modo que dos altas simultáneas del mismo DNI (una como estudiante y otra como docente) se serializan y
 * la segunda ve a la primera. SQLite no tiene ese bloqueo (y serializa las escrituras de todos modos). Los registros eliminados
 * lógicamente también ocupan el DNI: se restauran, no se duplican.
 */
final class DniUnico
{
    public const COMO_ESTUDIANTE = 'estudiante';

    public const COMO_DOCENTE = 'docente';

    /**
     * @param  string  $como  la entidad que se está escribiendo (`estudiante` o `docente`); se comprueba la OTRA
     *
     * @throws ValidationException si el DNI ya pertenece a la otra entidad
     */
    public static function comprobar(string $dni, string $como): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['dni:'.$dni]);
        }

        if ($como === self::COMO_ESTUDIANTE && Docente::withTrashed()->where('dni', $dni)->exists()) {
            throw ValidationException::withMessages(['dni' => 'Este DNI ya está registrado como docente.']);
        }
        if ($como === self::COMO_DOCENTE && Estudiante::withTrashed()->where('dni', $dni)->exists()) {
            throw ValidationException::withMessages(['dni' => 'Este DNI ya está registrado como estudiante.']);
        }
    }
}
