<?php

declare(strict_types=1);

namespace Horus\Local;

use InvalidArgumentException;

/**
 * Entorno defensivo por proceso para pruebas y operación local.
 *
 * Solo define variables de proceso: no lee ni escribe archivos de entorno. Una variable de proceso presente,
 * aunque vacía, prevalece sobre el archivo de entorno de Laravel y sobre el de Python, de modo que una clave
 * externa guardada en disco queda inerte sin modificarla. Quitar la variable no bastaría: el archivo de entorno
 * volvería a aportarla.
 *
 * Es la única lista de variables neutralizadas; la usan `scripts/entorno-seguro.php` y `scripts/horus-local.php`.
 */
final class EntornoSeguro
{
    /** Puerto sin servicio: cualquier HTTP externo que respete el proxy falla de inmediato. */
    public const PUERTO_SIN_SERVICIO = 9;

    /**
     * Familias que pueden permitirse de forma expresa y por separado.
     *
     * @var array<string, array<string, string>>
     */
    private const FAMILIAS = [
        'gemini' => [
            'GEMINI_API_KEY' => '',
        ],
        'correo' => [
            'MAIL_MAILER' => 'array',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '9',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'RESEND_API_KEY' => '',
            'MATRICULA_MAIL_ENABLED' => 'false',
            'RECLAMACIONES_CORREO_ENABLED' => 'false',
        ],
    ];

    /**
     * Siempre neutralizadas: ninguna familia las permite (OpenAI está retirado y no tiene consumidores).
     *
     * @var array<string, string>
     */
    private const SIEMPRE = [
        'OPENAI_API_KEY' => '',
    ];

    /** @return list<string> */
    public static function familias(): array
    {
        return array_keys(self::FAMILIAS);
    }

    /**
     * Entorno defensivo completo: ninguna llamada externa de las familias conocidas.
     *
     * @return array<string, string>
     */
    public static function neutralizado(): array
    {
        return self::permitiendo([]);
    }

    /**
     * Entorno defensivo salvo las familias permitidas de forma expresa. Permitir una familia solo retira sus
     * variables del perfil: el valor efectivo pasa a ser el del entorno heredado.
     *
     * @param  list<string>  $familias
     * @return array<string, string>
     */
    public static function permitiendo(array $familias): array
    {
        foreach ($familias as $familia) {
            if (! array_key_exists($familia, self::FAMILIAS)) {
                throw new InvalidArgumentException(
                    'Familia desconocida: '.$familia.'. Permitidas: '.implode(', ', self::familias()).'.'
                );
            }
        }

        $entorno = self::SIEMPRE;
        foreach (self::FAMILIAS as $nombre => $variables) {
            if (! in_array($nombre, $familias, true)) {
                $entorno += $variables;
            }
        }

        return $entorno;
    }

    /**
     * Segunda barrera para procesos que respetan proxies (pruebas PHP con el cliente HTTP de Laravel): todo
     * destino sale por un puerto sin servicio. No usar al arrancar servicios locales que se hablan por loopback.
     *
     * @return array<string, string>
     */
    public static function bloqueoDeRed(): array
    {
        $muerto = 'http://127.0.0.1:'.self::PUERTO_SIN_SERVICIO;

        return [
            'HTTP_PROXY' => $muerto,
            'HTTPS_PROXY' => $muerto,
            'ALL_PROXY' => $muerto,
            'http_proxy' => $muerto,
            'https_proxy' => $muerto,
            'all_proxy' => $muerto,
            'NO_PROXY' => '',
            'no_proxy' => '',
        ];
    }
}
