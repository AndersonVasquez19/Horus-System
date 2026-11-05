<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corte de activación persistente para la bienvenida de matrícula (Sprint 15-B, S15-03).
 *
 * Antes de esta política, las intenciones capturadas con el envío apagado quedaban `pending` y se
 * habrían despachado todas al activar Resend. El envío nunca se ha activado, así que toda fila
 * `pending` sin intentos existente al aplicar esta migración es anterior a la activación: pasa a
 * `held` con motivo explícito. No se borra nada, no se marca como enviada y no se tocan `retry`,
 * `sending`, `accepted`, `failed`, `uncertain` ni `skipped`.
 *
 * Desde aquí, el observer persiste `held` en cada captura sin envío configurado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('matricula_notifications')
            ->where('status', 'pending')
            ->where('attempts', 0)
            ->update([
                'status' => 'held',
                'last_error' => 'captured_before_policy',
                'payload' => null,
                'dispatched_until' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Irreversible a propósito: devolver estas filas a `pending` reactivaría el envío automático del
     * atraso. Cualquier liberación requiere una decisión administrativa explícita, fuera de un rollback.
     */
    public function down(): void
    {
        //
    }
};
