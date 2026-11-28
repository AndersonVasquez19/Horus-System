<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Matricula;
use App\Models\ObligacionPago;

/**
 * Regla única de elegibilidad para emitir un certificado. Una sola fuente para la emisión individual, el lote y la vista previa del
 * panel. La asistencia y las notas se retiraron (docs/retiro-sesiones-asistencia-calificaciones.md).
 *
 * Requisitos (cada incumplimiento es un bloqueo con código estable):
 *  - `deuda`: la obligación vigente de la matrícula tiene saldo sin confirmar. Un pago pendiente de confirmar no cuenta como pagado.
 *  - `acta`: solo si `academico.certificado_exige_acta_cerrada=true` (apagado por defecto): la edición debe estar en estado `cerrada`.
 *
 * Un administrador puede emitir pese a los bloqueos con un motivo (queda en el certificado y en la auditoría).
 *
 * @phpstan-type Bloqueo array{codigo: string, mensaje: string}
 */
class ElegibilidadCertificado
{
    public function __construct(private readonly GestionPagos $pagos) {}

    /**
     * @return list<Bloqueo>
     */
    public function bloqueos(Matricula $matricula): array
    {
        $bloqueos = [];

        $saldo = $this->saldoPendiente($matricula);
        if ($saldo > 0) {
            $bloqueos[] = ['codigo' => 'deuda', 'mensaje' => 'La matrícula tiene un saldo pendiente de S/ '.GestionPagos::formato($saldo).' en su obligación de pago.'];
        }

        if (config('academico.certificado_exige_acta_cerrada') === true && $matricula->convocatoria?->estado !== 'cerrada') {
            $bloqueos[] = ['codigo' => 'acta', 'mensaje' => 'La edición todavía no está cerrada.'];
        }

        return $bloqueos;
    }

    /** Saldo sin confirmar de la obligación vigente de la matrícula, en céntimos (0 si no tiene obligación). */
    public function saldoPendiente(Matricula $matricula): int
    {
        $obligacion = ObligacionPago::query()->where('matricula_id', $matricula->id)->where('estado', 'vigente')->first();
        if ($obligacion === null) {
            return 0;
        }
        $s = $this->pagos->saldos($obligacion);

        return max(0, $s['total'] - $s['confirmado']);
    }
}
