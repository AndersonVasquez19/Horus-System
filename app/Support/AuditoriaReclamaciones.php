<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use InvalidArgumentException;

/**
 * Auditoría del Libro de Reclamaciones sin valores personales.
 *
 * Solo se aceptan claves de una lista cerrada y valores escalares cortos con caracteres seguros
 * (identificadores, estados, fechas, nombres de campo). Cualquier otra clave o valor es un error de
 * programación y lanza excepción: nunca se descarta en silencio. El texto libre del caso vive en el historial.
 *
 * Como {@see AuditoriaAcademica}, debe invocarse como ÚLTIMA escritura dentro de la transacción de la operación.
 * En registros públicos (sin actor) no se guarda la IP del consumidor.
 */
final class AuditoriaReclamaciones
{
    public const ESQUEMA = 1;

    /** @var list<string> */
    private const CLAVES = ['numero', 'canal', 'tipo', 'estado', 'anterior', 'responsable_id', 'envio_id', 'envio_tipo', 'envio_canal', 'fecha_limite', 'dias_suspendidos', 'campos', 'resultado'];

    /** @param array<string, mixed> $metadatos */
    public static function registrar(string $accion, Reclamacion $reclamacion, array $metadatos, ?int $actorId): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actorId,
            'action' => 'reclamacion_'.$accion,
            'model_type' => Reclamacion::class,
            'model_id' => $reclamacion->getKey(),
            'changes' => ['esquema' => self::ESQUEMA] + self::limpiar($metadatos),
            'ip_address' => $actorId === null ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /** @var list<string> Filtros de la bandeja que pueden constar (por nombre; nunca su valor). */
    private const FILTROS_BANDEJA = ['busqueda', 'tipo', 'estado', 'desde', 'hasta'];

    /**
     * Consulta de la bandeja (E3). No apunta a una reclamación concreta (`model_id` nulo). Guarda solo los
     * NOMBRES de los filtros usados y la página: el término buscado y los resultados no se registran.
     *
     * @param  list<string>  $filtros
     */
    public static function registrarBandeja(int $actorId, array $filtros, int $pagina): AuditLog
    {
        $desconocidos = array_diff($filtros, self::FILTROS_BANDEJA);
        if ($desconocidos !== []) {
            throw new InvalidArgumentException('Filtro de bandeja no autorizado: '.implode(', ', $desconocidos));
        }
        if ($pagina < 1) {
            throw new InvalidArgumentException('La página de la bandeja debe ser positiva.');
        }

        return AuditLog::create([
            'user_id' => $actorId,
            'action' => 'reclamacion_bandeja_consultada',
            'model_type' => Reclamacion::class,
            'model_id' => null,
            'changes' => ['esquema' => self::ESQUEMA, 'filtros' => array_values(array_intersect(self::FILTROS_BANDEJA, $filtros)), 'pagina' => $pagina],
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadatos
     * @return array<string, mixed>
     */
    private static function limpiar(array $metadatos): array
    {
        foreach ($metadatos as $clave => $valor) {
            if (! in_array($clave, self::CLAVES, true)) {
                throw new InvalidArgumentException("Clave de auditoría no autorizada: {$clave}");
            }
            if ($clave === 'campos') {
                if (! is_array($valor) || array_filter($valor, fn ($v) => ! is_string($v) || ! self::seguro($v)) !== []) {
                    throw new InvalidArgumentException('«campos» solo admite nombres de campo.');
                }

                continue;
            }
            if ($valor !== null && ! is_int($valor) && ! is_bool($valor) && ! (is_string($valor) && self::seguro($valor))) {
                throw new InvalidArgumentException("Valor de auditoría no autorizado para {$clave}.");
            }
        }

        return $metadatos;
    }

    private static function seguro(string $valor): bool
    {
        return preg_match('/\A[A-Za-z0-9_:.\/-]{1,40}\z/D', $valor) === 1;
    }
}
