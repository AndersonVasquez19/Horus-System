<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detecta y enmascara datos de autenticación o secretos antes de auditarlos o exportarlos.
 *
 * La detección es por nombre de clave/columna (no por valor), de modo que también cubre
 * registros históricos y claves futuras sin necesidad de listarlas una por una.
 */
final class SensitiveData
{
    public const MASK = '[REDACTADO]';

    /** Fragmentos de nombre que identifican un secreto (comparación sin distinguir mayúsculas). */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'contrasena',
        'contraseña',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api_resend',
        'resend',
        'authorization',
        'remember',
        'credential',
        'private_key',
        'jwt',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        // Cualquier clave "api_*" o "*_api" se trata como credencial de integración.
        return preg_match('/(^|[_\-.])api([_\-.]|$)/', $normalized) === 1;
    }

    /**
     * Enmascara recursivamente los valores cuyas claves son sensibles.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $result[$key] = self::MASK;

                continue;
            }

            $result[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $result;
    }

    /**
     * Neutraliza celdas que una hoja de cálculo interpretaría como fórmula (CSV injection).
     */
    public static function csvSafe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        // Los números (incluidos negativos) no pueden ser fórmulas y deben conservarse tal cual.
        if (is_numeric($text)) {
            return $text;
        }

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
