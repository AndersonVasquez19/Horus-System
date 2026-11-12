<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

final class FormularioSolicitudMatricula
{
    public static function emitir(): string
    {
        return Crypt::encryptString(json_encode(['identificador' => (string) Str::uuid(), 'emitido' => now()->timestamp], JSON_THROW_ON_ERROR));
    }

    /** @return array{identificador: string, emitido: int}|null */
    public static function leer(mixed $marca): ?array
    {
        if (! is_string($marca) || strlen($marca) > 1024) {
            return null;
        }
        try {
            $datos = json_decode(Crypt::decryptString($marca), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        if (! is_array($datos) || ! is_string($datos['identificador'] ?? null) || ! Str::isUuid($datos['identificador'])
            || ! is_int($datos['emitido'] ?? null)) {
            return null;
        }
        $edad = now()->timestamp - $datos['emitido'];

        return $edad >= 2 && $edad <= 7200 ? ['identificador' => $datos['identificador'], 'emitido' => $datos['emitido']] : null;
    }
}
