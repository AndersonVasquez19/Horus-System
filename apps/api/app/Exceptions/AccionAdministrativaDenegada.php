<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\AuditoriaAdministrativa;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Operación administrativa denegada por las reglas de protección (HTTP 403 genérico).
 *
 * Se lanza dentro de la transacción: esta revierte todo. El registro de la denegación se escribe
 * DESPUÉS del rollback con {@see self::registrar()} (lo invoca `AdminProtection::runAdministrative()`),
 * de modo que la fila sobrevive. El mensaje es genérico a propósito: no revela si el objetivo es
 * administrador ni qué permiso faltaba.
 */
final class AccionAdministrativaDenegada extends HttpException
{
    public const MENSAJE = 'No tienes permiso para realizar esta acción.';

    private bool $registrada = false;

    /**
     * @param  array<string, mixed>  $contexto
     */
    public function __construct(
        public readonly string $regla,
        public readonly string $motivo,
        public readonly string $operacion,
        public readonly string $modelType,
        public readonly ?int $modelId,
        public readonly ?int $actorId,
        public readonly array $contexto = [],
    ) {
        parent::__construct(403, self::MENSAJE);
    }

    /**
     * Persiste la denegación una sola vez. Un fallo al auditar no cambia la respuesta (sigue siendo 403):
     * se deja una traza técnica sin datos del usuario.
     */
    public function registrar(): void
    {
        if ($this->registrada) {
            return;
        }
        $this->registrada = true;

        try {
            AuditoriaAdministrativa::denegacion(
                $this->actorId,
                $this->regla,
                $this->motivo,
                $this->operacion,
                $this->modelType,
                $this->modelId,
                $this->contexto,
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar una denegación administrativa.', [
                'regla' => $this->regla,
                'motivo' => $this->motivo,
                'error' => $e::class,
            ]);
        }
    }
}
