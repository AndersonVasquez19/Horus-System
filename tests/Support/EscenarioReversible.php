<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * Ejecuta un escenario dentro de una transacción ya abierta que SIEMPRE se revierte, sin ocultar ningún error y
 * sin declarar «limpio» lo que no pudo comprobar. Lo usa el arnés PostgreSQL de la migración 000032; está separado
 * y recibe cierres para poder probar sus caminos de error sin PostgreSQL.
 *
 * Reglas:
 *  - el error del escenario se conserva siempre (se relanza el mismo objeto si la limpieza fue correcta; si no, se
 *    incluye en el mensaje y queda como excepción previa);
 *  - si el escenario terminó bien, se comprueba ANTES del rollback que no dejó residuos;
 *  - el rollback se intenta siempre, aunque la comprobación previa falle; su error también se conserva;
 *  - el estado persistente se comprueba DESPUÉS del rollback siempre que la conexión lo permita (rollback sin
 *    error y nivel de transacción 0); si no, se informa expresamente que NO se pudo comprobar;
 *  - sin errores ni avisos, y con el estado persistente comprobado, la limpieza se considera satisfactoria.
 */
final class EscenarioReversible
{
    /**
     * @param  callable(): void  $escenario  trabajo de la prueba
     * @param  callable(): void  $sinResiduos  comprobación previa al rollback (solo si el escenario terminó bien)
     * @param  callable(): void  $revertir  rollback de la transacción abierta
     * @param  callable(): int  $nivel  nivel de transacción actual de la conexión
     * @param  callable(): void  $estadoPersistente  comprobación posterior al rollback
     *
     * @throws Throwable el error original intacto si la limpieza fue correcta; AssertionFailedError con todo si no
     */
    public static function ejecutar(callable $escenario, callable $sinResiduos, callable $revertir, callable $nivel, callable $estadoPersistente): void
    {
        $original = null;
        try {
            $escenario();
        } catch (Throwable $e) {
            $original = $e;
        }

        /** @var array<string, Throwable|string> $problemas */
        $problemas = [];

        if ($original === null) {
            try {
                $sinResiduos();
            } catch (Throwable $e) {
                $problemas['Residuos antes del rollback'] = $e;
            }
        }

        $rollbackFallo = false;
        try {
            $revertir();
        } catch (Throwable $e) {
            $rollbackFallo = true;
            $problemas['Fallo del rollback'] = $e;
        }

        $motivoSinComprobar = null;
        if ($rollbackFallo) {
            $motivoSinComprobar = 'el rollback falló: la conexión puede conservar una transacción abierta';
        } else {
            try {
                $actual = $nivel();
                if ($actual !== 0) {
                    $motivoSinComprobar = "la conexión sigue en el nivel de transacción $actual tras el rollback";
                }
            } catch (Throwable $e) {
                $problemas['No se pudo leer el nivel de transacción'] = $e;
                $motivoSinComprobar = 'no se pudo leer el nivel de transacción';
            }
        }

        if ($motivoSinComprobar === null) {
            try {
                $estadoPersistente();
            } catch (Throwable $e) {
                $problemas['Estado persistente incorrecto tras el rollback'] = $e;
            }
        } else {
            $problemas['Estado persistente NO comprobado'] = $motivoSinComprobar.'; no se declara limpieza satisfactoria';
        }

        if ($problemas === []) {
            if ($original !== null) {
                throw $original;
            }

            return;
        }

        $mensaje = 'Limpieza del escenario no satisfactoria.';
        if ($original !== null) {
            $mensaje .= ' Error original: '.self::describir($original).'.';
        }
        foreach ($problemas as $etiqueta => $problema) {
            $mensaje .= " $etiqueta: ".($problema instanceof Throwable ? self::describir($problema) : $problema).'.';
        }
        $previo = $original ?? array_values(array_filter($problemas, fn ($p): bool => $p instanceof Throwable))[0] ?? null;

        throw new AssertionFailedError($mensaje, 0, $previo);
    }

    private static function describir(Throwable $e): string
    {
        return $e::class.': '.$e->getMessage();
    }
}
