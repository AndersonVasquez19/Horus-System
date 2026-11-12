<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de una entidad académica a partir de su auditoría, minimizado para quien ve el módulo (no requiere
 * `auditoria.ver`): acción, fecha, autor, nombres de los campos cambiados y la transición de estado. Nunca IP, ni
 * valores anteriores de datos personales, montos u observaciones.
 */
final class HistorialAuditoria
{
    /**
     * @param  class-string<Model>  $modelo
     * @param  list<string>  $acciones  lista cerrada de acciones que forman el historial
     * @return list<array<string, mixed>>
     */
    public static function de(string $modelo, int $id, array $acciones, int $limite = 50): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where('model_type', $modelo)
            ->where('model_id', $id)
            ->whereIn('action', $acciones)
            ->latest('created_at')
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(function (AuditLog $registro): array {
                $cambios = $registro->redactedChanges();
                $antes = is_array($cambios['antes'] ?? null) ? $cambios['antes'] : [];
                $despues = is_array($cambios['despues'] ?? null) ? $cambios['despues'] : [];
                $valores = is_array($cambios['valores'] ?? null) ? $cambios['valores'] : [];
                $campos = is_array($cambios['campos'] ?? null) ? array_values(array_filter($cambios['campos'], 'is_string')) : [];

                return [
                    'id' => $registro->id,
                    'accion' => $registro->action,
                    'fecha' => $registro->created_at->toIso8601String(),
                    'usuario' => $registro->user?->name,
                    'campos' => $campos,
                    'estado_anterior' => is_string($antes['estado'] ?? null) ? $antes['estado'] : null,
                    'estado_nuevo' => match (true) {
                        is_string($despues['estado'] ?? null) => $despues['estado'],
                        is_string($valores['estado'] ?? null) => $valores['estado'],
                        is_string($cambios['estado'] ?? null) => $cambios['estado'],
                        default => null,
                    },
                ];
            })
            ->all();
    }
}
