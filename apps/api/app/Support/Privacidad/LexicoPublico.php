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

    /** @var array<string, true>|null */
    private static ?array $clavesFoneticasDominio = null;

    /** @var list<string>|null */
    private static ?array $clavesFoneticasLargas = null;

    /**
     * Vocabulario cerrado del dominio educativo e institucional de Horus Group para tolerar
     * escritura fonética o faltas ortográficas frecuentes del visitante («kurso», «birtual»,
     * «sertifikado», «kuando», «enpresa», «jerente») sin relajar el complemento del léxico
     * sobre los ~30 000 términos generales ni sobre nombres de personas.
     *
     * @var list<string>
     */
    private const DOMINIO_FONETICO = [
        'cual', 'cuales', 'cuando', 'cuanto', 'cuanta', 'cuantos', 'cuantas', 'quien', 'quienes', 'como', 'donde',
        'hasta', 'quiero', 'quiere', 'quieren', 'quisiera', 'necesito', 'necesita', 'busco', 'queda', 'quedan',
        'cuesta', 'cuestan', 'empieza', 'empiezan', 'sirve', 'sirven', 'abren', 'cierran', 'atienden', 'trabajan',
        'hablar', 'recomiendes', 'recomiendas', 'recupero', 'recuperar', 'agendar', 'devuelven', 'devolucion',
        'sacar', 'saco', 'enviar', 'envian', 'contestar', 'contestan', 'postular', 'elegir', 'aprender', 'pasame',
        'puedo', 'tienen', 'horus', 'empresa', 'mision', 'vision', 'gerente', 'general', 'curso', 'cursos', 'redes',
        'virtual', 'virtuales', 'presencial', 'presenciales', 'semipresencial', 'cableado', 'cero', 'corto',
        'ofimatica', 'boleta', 'factura', 'electronica', 'electronico', 'cupos', 'vacantes', 'vence', 'convenio',
        'convenios', 'asesoria', 'asesoramiento', 'legal', 'abogado', 'tiempo', 'consulta', 'certificado',
        'certificados', 'duplicado', 'fisico', 'digital', 'provincias', 'reclamo', 'reclamaciones', 'clase',
        'clases', 'practica', 'practicas', 'hora', 'horarios', 'horario', 'oficina', 'asesor', 'humano', 'correo',
        'videollamada', 'galeria', 'fotos', 'examenes', 'grabados', 'matricula', 'inscripcion', 'inscribirme',
        'matricularme', 'whatsapp', 'facebook', 'instagram', 'tiktok', 'excel', 'word', 'corlad', 'indecopi', 'sunat',
    ];

    /**
     * Grafías coloquiales o fonéticas breves e inequívocas del dominio (no coinciden con nombres).
     *
     * @var array<string, true>
     */
    private const VARIANTES_EXACTAS_DOMINIO = [
        'ruk' => true,
        'exel' => true,
        'xfa' => true,
        'wsp' => true,
        'guasap' => true,
        'feisbuk' => true,
        'istangram' => true,
        'tictok' => true,
        'arko' => true,
        'aseroia' => true,
        'lejal' => true,
    ];

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

    /** Longitud mínima para tolerar un error de tecleo: en palabras largas un nombre propio casi nunca difiere en una letra. */
    public const LARGO_MINIMO_ERRATA = 9;

    /**
     * Errata de tecleo: palabra normalizada de {@see LARGO_MINIMO_ERRATA} letras o más que difiere en UNA sola letra
     * (cambio, sobra o falta) de una entrada del léxico de igual o mayor longitud mínima («matricuna» → «matricula»).
     * Las formas muy cortas no se toleran: «Mario» o «Sara» no se vuelven conocidos por parecerse a otra palabra.
     */
    public static function contieneConErrata(string $palabra): bool
    {
        $largo = strlen($palabra);
        if ($largo < self::LARGO_MINIMO_ERRATA || $largo > 40 || preg_match('/^[a-z]+$/', $palabra) !== 1) {
            return false;
        }
        foreach (self::$palabras ??= self::cargar() as $entrada => $_) {
            $e = (string) $entrada;
            $d = strlen($e) - $largo;
            if ($d < -1 || $d > 1 || strlen($e) < self::LARGO_MINIMO_ERRATA) {
                continue;
            }
            if ($e[0] === $palabra[0] && levenshtein($palabra, $e) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variante fonética u ortográfica en español de una palabra del dominio cerrado de Horus Group.
     * Solo se evalúa después de descartar nombres y apellidos listados.
     */
    public static function esVarianteFoneticaDeDominio(string $palabra): bool
    {
        if (isset(self::VARIANTES_EXACTAS_DOMINIO[$palabra])) {
            return true;
        }

        $largo = strlen($palabra);
        if ($largo < 4 || $largo > 30 || preg_match('/^[a-z]+$/', $palabra) !== 1) {
            return false;
        }

        if (self::$clavesFoneticasDominio === null) {
            self::$clavesFoneticasDominio = [];
            self::$clavesFoneticasLargas = [];
            foreach (self::DOMINIO_FONETICO as $termino) {
                $clave = self::claveFonetica($termino);
                self::$clavesFoneticasDominio[$clave] = true;
                if (strlen($clave) >= 6) {
                    self::$clavesFoneticasLargas[] = $clave;
                }
            }
            self::$clavesFoneticasLargas = array_values(array_unique(self::$clavesFoneticasLargas));
        }

        $claveEntrada = self::claveFonetica($palabra);
        if (isset(self::$clavesFoneticasDominio[$claveEntrada])) {
            return true;
        }

        $largoClave = strlen($claveEntrada);
        if ($largoClave < 6) {
            return false;
        }

        foreach (self::$clavesFoneticasLargas ?? [] as $claveDominio) {
            $dif = strlen($claveDominio) - $largoClave;
            if ($dif < -1 || $dif > 1) {
                continue;
            }
            if (levenshtein($claveEntrada, $claveDominio) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function claveFonetica(string $palabra): string
    {
        $clave = preg_replace('/(?<!c)h/', '', $palabra) ?? $palabra;
        $clave = preg_replace('/qu(?=[ei])/', 'k', $clave) ?? $clave;
        $clave = preg_replace('/c(?=[ei])/', 's', $clave) ?? $clave;
        $clave = preg_replace('/g(?=[ei])/', 'j', $clave) ?? $clave;
        $clave = str_replace(['ll', 'np', 'nb', 'ks', 'ck'], ['y', 'mp', 'mb', 'x', 'k'], $clave);
        $clave = strtr($clave, ['c' => 'k', 'z' => 's', 'v' => 'b']);

        return preg_replace('/(.)\1+/', '$1', $clave) ?? $clave;
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
