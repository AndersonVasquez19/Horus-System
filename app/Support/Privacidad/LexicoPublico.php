<?php

declare(strict_types=1);

namespace App\Support\Privacidad;

/**
 * Vocabulario común (no nombres propios) que el filtro de privacidad usa para decidir qué palabras de un mensaje
 * «se explican solas». Lo que no está aquí se trata como posible nombre propio.
 *
 * Se genera con scripts/privacidad-generar-lexico.php desde fuentes verificables del repositorio (lista curada,
 * texto público del sitio y datos públicos del asistente). El archivo se carga una vez por proceso como arreglo PHP
 * (inmutable y compartido por OPcache); no hay red, base de datos ni modelos.
 *
 * Limitación: el léxico no puede cubrir todo el español; una palabra legítima poco frecuente seguida de otra
 * igual de infrecuente puede bloquearse por prudencia (falso positivo aceptado frente a transmitir un nombre).
 */
final class LexicoPublico
{
    /** Valor de una entrada que admite plural y cambio de género (sustantivos y adjetivos). */
    private const FLEXIBLE = 1;

    /** @var array<string, int>|null */
    private static ?array $palabras = null;

    /**
     * Palabra normalizada (minúsculas, sin tildes, ñ→n). Coincidencia exacta con cualquier entrada; plural, género
     * (a↔o) y adverbio en -mente solo se derivan de sustantivos y adjetivos (valor 1). Las palabras funcionales y las
     * formas verbales no se derivan: «daría» (verbo) no vuelve «conocido» a «Darío».
     */
    public static function contiene(string $palabra): bool
    {
        $mapa = self::$palabras ??= self::cargar();
        if (isset($mapa[$palabra])) {
            return true;
        }
        $largo = strlen($palabra);
        $candidatas = [$palabra];
        if ($largo > 3 && str_ends_with($palabra, 'es')) {
            $candidatas[] = substr($palabra, 0, -2);
            if (str_ends_with($palabra, 'ces')) {
                $candidatas[] = substr($palabra, 0, -3).'z';
            }
        }
        if ($largo > 3 && str_ends_with($palabra, 's')) {
            $candidatas[] = substr($palabra, 0, -1);
        }
        if ($largo > 7 && str_ends_with($palabra, 'mente')) {
            $candidatas[] = substr($palabra, 0, -5);
        }
        foreach ($candidatas as $c) {
            if (($mapa[$c] ?? 0) === self::FLEXIBLE) {
                return true;
            }
            // El cambio de género exige palabras de cinco letras o más: «mega» no vuelve conocido a «Mego», ni «sara» a «Saro».
            $ultima = substr($c, -1);
            if (strlen($c) >= 5 && $ultima === 'a' && ($mapa[substr($c, 0, -1).'o'] ?? 0) === self::FLEXIBLE) {
                return true;
            }
            if (strlen($c) >= 5 && $ultima === 'o' && ($mapa[substr($c, 0, -1).'a'] ?? 0) === self::FLEXIBLE) {
                return true;
            }
        }

        // Derivación productiva de baja ambigüedad en palabras largas («creación», «seguridad», «fotográfico»). Los
        // nombres propios con estas terminaciones son listados aparte (Concepción, Trinidad…) y se evalúan antes.
        if (($largo >= 7 && preg_match('/(?:cion|ciones|sion|siones|dad|dades|miento|mientos)$/', $palabra) === 1)
            || ($largo >= 9 && preg_match('/(?:ismos?|ivos?|ivas?|ic[oa]s?)$/', $palabra) === 1)) {
            return true;
        }

        // Formas verbales y sustantivos de agente largos: infinitivos en -ar/-ir («investigar»), con clítico («matricularme»),
        // gerundios y agentes en -dor/-tor/-sor. Se excluye -er sin clítico porque Jennifer, Christopher o Esther son nombres.
        if (($largo >= 10 && preg_match('/(?:ar|ir)$/', $palabra) === 1)
            || $largo >= 8 && (preg_match('/(?:(?:ar|er|ir)(?:me|te|se|nos|lo|la|le|les|los|las)|iendo(?:me|te|se|nos|lo|la)?|dores?|doras?|tores?|toras?|sores?|soras?)$/', $palabra) === 1
            || ($largo >= 9 && preg_match('/ando(?:me|te|se|nos|lo|la)?$/', $palabra) === 1))) {
            return true;
        }

        return false;
    }

    public static function total(): int
    {
        return count(self::$palabras ??= self::cargar());
    }

    /** @return array<string, int> */
    private static function cargar(): array
    {
        /** @var array<string, int> $palabras */
        $palabras = require __DIR__.'/lexico-publico.php';

        return $palabras;
    }
}
