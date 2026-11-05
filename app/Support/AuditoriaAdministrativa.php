<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use InvalidArgumentException;

/**
 * Auditoría de las denegaciones de operaciones administrativas (usuarios y roles).
 *
 * Independiente de {@see AuditoriaAcademica}. Principios (Sprint 21.1, D-1/D-9):
 * - Lista cerrada de reglas, motivos y claves de contexto: nada de texto libre ni de `$request->all()`.
 * - Solo se guardan nombres técnicos de roles y permisos. Nunca correos, nombres de personas, DNI,
 *   contraseñas, hashes, tokens, cookies ni identificadores de sesión.
 * - El actor se recibe de forma explícita (no se infiere de la sesión).
 */
final class AuditoriaAdministrativa
{
    public const ESQUEMA = 1;

    public const ACCION_DENEGADO = 'acceso_denegado';

    public const CATEGORIA = 'autorizacion';

    /** @var list<string> */
    public const REGLAS = ['ACTOR', 'R1', 'R1+', 'R2a', 'R2b', 'R2c', 'R2d', 'R3', 'R4a', 'R4b', 'R4c', 'R4e'];

    /** @var list<string> */
    public const MOTIVOS = [
        'actor_sin_permiso_vigente',
        'admin_protegido_edicion',
        'admin_protegido_eliminacion',
        'cuenta_fuera_de_alcance',
        'sin_permiso_asignar_roles',
        'autoasignacion_roles',
        'rol_admin_reservado',
        'rol_fuera_de_alcance',
        'rol_admin_protegido',
        'rol_propio_no_editable',
        'permisos_fuera_de_alcance',
    ];

    /** Claves de contexto admitidas y su tipo. */
    private const CONTEXTO = [
        'roles_solicitados' => 'lista',
        'permisos_excedidos' => 'lista',
        'objetivo_es_admin' => 'bool',
        'objetivo_es_actor' => 'bool',
    ];

    private const MAX_ELEMENTOS = 50;

    private const MAX_LONGITUD = 100;

    /**
     * @param  array<string, mixed>  $contexto
     */
    public static function denegacion(
        ?int $actorId,
        string $regla,
        string $motivo,
        string $operacion,
        string $modelType,
        ?int $modelId,
        array $contexto = [],
    ): AuditLog {
        if (! in_array($regla, self::REGLAS, true) || ! in_array($motivo, self::MOTIVOS, true)) {
            throw new InvalidArgumentException('Regla o motivo de auditoría desconocidos.');
        }

        return AuditLog::record(self::ACCION_DENEGADO, $modelType, $modelId, [
            'esquema' => self::ESQUEMA,
            'categoria' => self::CATEGORIA,
            'regla' => $regla,
            'motivo' => $motivo,
            'operacion' => self::limpiar($operacion),
            'contexto' => self::contexto($contexto),
        ], $actorId);
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    public static function contexto(array $contexto): array
    {
        $limpio = [];

        foreach (self::CONTEXTO as $clave => $tipo) {
            if (! array_key_exists($clave, $contexto)) {
                continue;
            }

            $valor = $contexto[$clave];

            if ($tipo === 'bool') {
                $limpio[$clave] = (bool) $valor;

                continue;
            }

            if (! is_array($valor)) {
                continue;
            }

            $nombres = [];
            foreach ($valor as $elemento) {
                if (is_string($elemento)) {
                    $nombres[] = self::limpiar($elemento);
                }
                if (count($nombres) >= self::MAX_ELEMENTOS) {
                    break;
                }
            }
            $limpio[$clave] = array_values(array_unique($nombres));
        }

        return $limpio;
    }

    private static function limpiar(string $valor): string
    {
        $sinControl = preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '';

        return mb_substr($sinControl, 0, self::MAX_LONGITUD);
    }
}
