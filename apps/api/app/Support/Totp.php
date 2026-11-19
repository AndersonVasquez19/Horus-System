<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * TOTP RFC 6238 (SHA-1, 6 dígitos, paso 30s) y códigos de recuperación de un solo uso sin dependencias externas.
 */
final class Totp
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const DIGITOS = 6;

    private const PERIODO = 30;

    public static function generarSecreto(int $bytes = 20): string
    {
        return self::codificarBase32(random_bytes($bytes));
    }

    public static function uriProvision(string $emisor, string $cuenta, string $secreto): string
    {
        $etiqueta = rawurlencode($emisor.':'.$cuenta);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $etiqueta,
            rawurlencode($secreto),
            rawurlencode($emisor),
            self::DIGITOS,
            self::PERIODO,
        );
    }

    public static function codigoEn(string $secreto, ?int $timestamp = null): string
    {
        $binario = self::decodificarBase32($secreto);
        if ($binario === '') {
            return '';
        }

        $contador = intdiv($timestamp ?? time(), self::PERIODO);
        $tiempo = pack('N*', 0, $contador);
        $hash = hash_hmac('sha1', $tiempo, $binario, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncado = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );
        $modulo = 10 ** self::DIGITOS;

        return str_pad((string) ($truncado % $modulo), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    public static function verificar(string $secreto, string $codigo, int $ventana = 1, ?int $timestamp = null): bool
    {
        return self::pasoValido($secreto, $codigo, $ventana, $timestamp) !== null;
    }

    /**
     * Paso (contador de 30 s) al que corresponde el código, o null si no es válido. Quien lo usa debe guardar el último paso aceptado
     * y rechazar pasos iguales o anteriores: un código TOTP no puede servir dos veces.
     */
    public static function pasoValido(string $secreto, string $codigo, int $ventana = 1, ?int $timestamp = null): ?int
    {
        $limpio = preg_replace('/\s+/', '', $codigo) ?? '';
        if (preg_match('/\A\d{6}\z/', $limpio) !== 1) {
            return null;
        }

        $ahora = $timestamp ?? time();
        $encontrado = null;
        for ($i = -$ventana; $i <= $ventana; $i++) {
            $instante = $ahora + ($i * self::PERIODO);
            $esperado = self::codigoEn($secreto, $instante);
            // Se recorren todos los pasos (sin cortar al primero) para no filtrar por tiempo cuál coincidió.
            if ($esperado !== '' && hash_equals($esperado, $limpio)) {
                $encontrado = intdiv($instante, self::PERIODO);
            }
        }

        return $encontrado;
    }

    /**
     * Genera 8 códigos legibles (en claro para mostrar una única vez) y sus hashes para guardar cifrados.
     *
     * @return array{planos: list<string>, hashes: list<string>}
     */
    public static function generarCodigosRecuperacion(int $cantidad = 8): array
    {
        $planos = [];
        $hashes = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $codigo = strtoupper(bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)));
            $planos[] = $codigo;
            $hashes[] = Hash::make($codigo);
        }

        return ['planos' => $planos, 'hashes' => $hashes];
    }

    /**
     * Verifica y consume un código de recuperación de un solo uso. Devuelve el arreglo actualizado de hashes, o null si no coincide.
     *
     * @param  array<mixed>  $hashes
     * @return list<string>|null
     */
    public static function consumirCodigoRecuperacion(array $hashes, string $entrada): ?array
    {
        $normalizado = strtoupper(trim($entrada));
        if ($normalizado === '') {
            return null;
        }

        $restantes = [];
        $encontrado = false;
        foreach ($hashes as $hash) {
            if (! is_string($hash)) {
                continue;
            }
            if (! $encontrado && Hash::check($normalizado, $hash)) {
                $encontrado = true;

                continue;
            }
            $restantes[] = $hash;
        }

        return $encontrado ? $restantes : null;
    }

    public static function codificarBase32(string $datos): string
    {
        $bits = '';
        $largo = strlen($datos);
        for ($i = 0; $i < $largo; $i++) {
            $bits .= str_pad(decbin(ord($datos[$i])), 8, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $salida .= self::ALFABETO[bindec($chunk)];
        }

        return $salida;
    }

    public static function decodificarBase32(string $base32): string
    {
        $limpio = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $base32) ?? '');
        if ($limpio === '') {
            return '';
        }

        $bits = '';
        $largo = strlen($limpio);
        for ($i = 0; $i < $largo; $i++) {
            $pos = strpos(self::ALFABETO, $limpio[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $salida .= chr((int) bindec($byte));
            }
        }

        return $salida;
    }
}
