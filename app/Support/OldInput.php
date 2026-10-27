<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Valor anterior (old()) de un campo escalar de formulario, listo para imprimir.
 *
 * Si la petición llegó manipulada (campo[]=x), la validación la rechaza y old() devuelve un array;
 * imprimirlo provoca un error 500 (Array to string conversion / htmlspecialchars). Aquí cualquier
 * valor que no sea escalar se muestra como vacío: no se convierte a texto ni se reinterpreta como un
 * valor válido. Un valor escalar se devuelve como texto y Blade lo sigue escapando con {{ }}.
 *
 * No es para campos que aceptan arrays legítimamente (roles[], permissions[]).
 */
final class OldInput
{
    public static function value(string $key, mixed $default = ''): string
    {
        $value = old($key, $default);

        return is_scalar($value) ? (string) $value : '';
    }
}
