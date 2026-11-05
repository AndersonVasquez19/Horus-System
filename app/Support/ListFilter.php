<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Lectura de filtros de texto de los listados del panel (mismo origen que $request->input()).
 *
 * Un parámetro puede llegar con forma de array (?busqueda[]=x). Convertirlo con (string) provoca
 * "Array to string conversion" y un error 500; aquí cualquier valor que no sea texto se trata
 * como filtro vacío, igual que si no se hubiera enviado.
 */
final class ListFilter
{
    public static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
