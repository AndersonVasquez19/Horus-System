<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * Marca de un solo uso del formulario público de cotización: cifrada y autenticada con la clave de la aplicación,
 * lleva el identificador de la intención (clave de idempotencia) y su hora de emisión. Se exige una antigüedad mínima
 * (los bots rellenan al instante) y una máxima (marcas reutilizadas). No contiene datos personales.
 */
final class FormularioCotizacionPublica
{
    public const SEGUNDOS_MINIMOS = 3;

    public const SEGUNDOS_MAXIMOS = 7200;

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
        $emitido = is_array($datos) ? ($datos['emitido'] ?? null) : null;
        $identificador = is_array($datos) ? ($datos['identificador'] ?? null) : null;
        if (! is_string($identificador) || ! Str::isUuid($identificador) || ! is_int($emitido)) {
            return null;
        }
        $edad = now()->getTimestamp() - $emitido;

        return $edad >= self::SEGUNDOS_MINIMOS && $edad <= self::SEGUNDOS_MAXIMOS
            ? ['identificador' => $identificador, 'emitido' => $emitido] : null;
    }
}
