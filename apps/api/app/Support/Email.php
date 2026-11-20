<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Normalización única del correo de acceso (recorte + minúsculas) y unicidad sin distinguir mayúsculas.
 * Un valor que no es texto (array, número, null) se trata como cadena vacía y falla la validación
 * en vez de provocar "Array to string conversion".
 */
final class Email
{
    public static function normalize(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }

    /** Sustituye el campo `email` de la petición por su forma normalizada. */
    public static function normalizeRequest(Request $request, string $key = 'email'): void
    {
        $request->merge([$key => self::normalize($request->input($key))]);
    }

    /** Regla de unicidad que ignora mayúsculas (también frente a filas antiguas con mayúsculas). */
    public static function uniqueRule(?int $ignoreUserId = null, string $message = 'Este correo ya está registrado.'): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($ignoreUserId, $message): void {
            $query = User::query()->whereRaw('lower(email) = ?', [self::normalize($value)]);

            if ($ignoreUserId !== null) {
                $query->whereKeyNot($ignoreUserId);
            }

            if ($query->exists()) {
                $fail($message);
            }
        };
    }
}
