<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Iguala el trabajo de hash entre cuentas existentes y ausentes para que la latencia del inicio de sesión no
 * revele si un correo está registrado. El hash ficticio se calcula una vez por proceso con el mismo hasher
 * (y por tanto el mismo coste) que las contraseñas reales.
 */
final class CredentialTiming
{
    private static ?string $dummyHash = null;

    /**
     * Comprueba la contraseña contra el hash de la cuenta o, si no existe, contra un hash ficticio.
     * Siempre ejecuta exactamente una comprobación; con cuenta ausente devuelve false.
     */
    public static function check(string $password, ?string $hash): bool
    {
        $valid = Hash::check($password, $hash ?? self::dummyHash());

        return $hash !== null && $valid;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }
}
