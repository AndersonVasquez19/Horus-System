<?php

declare(strict_types=1);

namespace App\Support;

use Stringable;

/**
 * Conversión explícita de valores `mixed` (datos validados de una petición, configuración, JSON decodificado) a un tipo
 * concreto. Equivale al cast de PHP para escalares y `null`; ante un valor que no es convertible (arreglos, objetos) devuelve el
 * valor por defecto en lugar de un resultado engañoso («Array», 1). Existe para que el análisis estático (nivel 9) vea un tipo
 * concreto sin esconder la conversión detrás de un cast sobre `mixed`.
 */
final class Entrada
{
    public static function entero(mixed $valor, int $porDefecto = 0): int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_scalar($valor) || $valor === null ? (int) $valor : $porDefecto;
    }

    public static function texto(mixed $valor, string $porDefecto = ''): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        return is_scalar($valor) || $valor === null || $valor instanceof Stringable ? (string) $valor : $porDefecto;
    }

    public static function decimal(mixed $valor, float $porDefecto = 0.0): float
    {
        if (is_float($valor)) {
            return $valor;
        }

        return is_scalar($valor) || $valor === null ? (float) $valor : $porDefecto;
    }

    public static function booleano(mixed $valor): bool
    {
        return is_scalar($valor) || $valor === null ? (bool) $valor : false;
    }

    /** Valor escalar o `null` tal cual (para escribir celdas de una hoja de cálculo); cualquier otra cosa queda como texto vacío. */
    public static function celda(mixed $valor): string|int|float|null
    {
        if ($valor === null || is_string($valor) || is_int($valor) || is_float($valor)) {
            return $valor;
        }

        return is_bool($valor) ? (int) $valor : '';
    }

    /** Texto si el valor ya es una cadena; `null` en cualquier otro caso (p. ej. un nombre de conexión o cola opcional). */
    public static function textoONulo(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : null;
    }

    /**
     * Arreglo con claves de texto (una fila de base de datos, un objeto simple); cualquier otra cosa da un arreglo vacío.
     *
     * @return array<string, mixed>
     */
    public static function mapa(mixed $valor): array
    {
        if (is_object($valor)) {
            $valor = get_object_vars($valor);
        }
        if (! is_array($valor)) {
            return [];
        }
        $mapa = [];
        foreach ($valor as $clave => $elemento) {
            $mapa[(string) $clave] = $elemento;
        }

        return $mapa;
    }

    /**
     * @return list<string>
     */
    public static function listaDeTexto(mixed $valor): array
    {
        return array_values(array_map(static fn (mixed $elemento): string => self::texto($elemento), self::lista($valor)));
    }

    /**
     * @return array<mixed>
     */
    public static function lista(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }
}
