<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Fallo controlado de la consulta de DNI. El mensaje es apto para mostrarse a la persona operadora: nunca contiene el token,
 * la URL del proveedor ni texto devuelto por el proveedor. `estado` es el código HTTP con que la API del panel responde.
 */
final class ConsultaDniException extends RuntimeException
{
    public const NO_ENCONTRADO = 'dni_no_encontrado';

    public const NO_CONFIGURADO = 'dni_servicio_no_configurado';

    public const NO_AUTORIZADO = 'dni_servicio_no_autorizado';

    public const LIMITE = 'dni_limite_proveedor';

    public const NO_DISPONIBLE = 'dni_servicio_no_disponible';

    public const RESPUESTA_INCOMPLETA = 'dni_respuesta_incompleta';

    private const MENSAJES = [
        self::NO_ENCONTRADO => ['No se encontró información para ese DNI. Verifica el número.', 404],
        self::NO_CONFIGURADO => ['La consulta de DNI no está configurada. Contacta al administrador.', 503],
        self::NO_AUTORIZADO => ['La consulta de DNI no está autorizada en este momento. Contacta al administrador.', 503],
        self::LIMITE => ['Se alcanzó el límite de consultas del servicio. Inténtalo más tarde.', 429],
        self::NO_DISPONIBLE => ['El servicio de consulta de DNI no está disponible. Inténtalo nuevamente o ingresa los datos manualmente.', 503],
        self::RESPUESTA_INCOMPLETA => ['El servicio devolvió una respuesta incompleta. Inténtalo nuevamente o ingresa los datos manualmente.', 502],
    ];

    private function __construct(public readonly string $codigoError, string $mensaje, public readonly int $estado)
    {
        parent::__construct($mensaje);
    }

    public static function de(string $codigo): self
    {
        [$mensaje, $estado] = self::MENSAJES[$codigo];

        return new self($codigo, $mensaje, $estado);
    }
}
