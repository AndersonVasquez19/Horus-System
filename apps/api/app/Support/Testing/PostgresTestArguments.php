<?php

declare(strict_types=1);

namespace App\Support\Testing;

use RuntimeException;

/**
 * Opciones de PHPUnit aceptadas por `php scripts/pgsql-test.php test` (lista cerrada).
 *
 * PHPUnit 11 separa `--stop-on-failure` (solo fallos) de `--stop-on-error` (solo errores), y
 * `--stop-on-defect` también se detiene ante pruebas «risky», por lo que no sirve aquí. La opción
 * `--stop-on-error` del script se traduce en ambas opciones de PHPUnit: la ejecución termina en el
 * primer error o fallo. No se añade ninguna opción que oculte errores, fallos ni avisos «risky».
 */
final class PostgresTestArguments
{
    public const STOP_ON_FAILURE = '--stop-on-failure';

    public const STOP_ON_ERROR = '--stop-on-error';

    /**
     * @param  list<string>  $arguments  Argumentos que siguen a `test`.
     * @return list<string> Opciones que se pasan a PHPUnit, sin duplicados.
     */
    public static function phpunitOptions(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--filter=') && $argument !== '--filter=') {
                $options[] = $argument;
            } elseif ($argument === self::STOP_ON_FAILURE) {
                $options[] = self::STOP_ON_FAILURE;
            } elseif ($argument === self::STOP_ON_ERROR) {
                array_push($options, self::STOP_ON_ERROR, self::STOP_ON_FAILURE);
            } else {
                throw new RuntimeException('Only --filter=..., --stop-on-failure and --stop-on-error are accepted.');
            }
        }

        return array_values(array_unique($options));
    }
}
