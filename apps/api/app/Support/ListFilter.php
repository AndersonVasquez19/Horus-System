<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Lectura de filtros de texto de los listados del panel (mismo origen que $request->input()).
 *
 * Un parámetro puede llegar con forma de array (?busqueda[]=x). Convertirlo con (string) provoca
 * "Array to string conversion" y un error 500; aquí cualquier valor que no sea texto se trata
 * como filtro vacío, igual que si no se hubiera enviado, y se acota su longitud máxima.
 */
final class ListFilter
{
    public const MAX_LENGTH = 200;

    public static function text(Request $request, string $key, int $maxLength = self::MAX_LENGTH): string
    {
        $value = $request->input($key);
        if (! is_string($value)) {
            return '';
        }

        $trimmed = trim($value);

        return $maxLength > 0 ? mb_substr($trimmed, 0, $maxLength) : $trimmed;
    }

    /**
     * Devuelve el valor solo si es una cadena incluida en la lista permitida; en otro caso devuelve `''`.
     *
     * @param  list<string>  $allowed
     */
    public static function in(Request $request, string $key, array $allowed): string
    {
        $value = $request->input($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
