<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Support\Entrada;

/**
 * Regla única de elegibilidad para emitir un certificado (Fase 3.5). Una sola fuente para la emisión individual, el lote y la vista
 * previa del panel. Umbrales en `config/academico.php`.
 *
 * Requisitos (cada incumplimiento es un bloqueo con código estable):
 *  - `asistencia`: porcentaje registrado menor al mínimo (sin datos de asistencia no bloquea, como antes).
 *  - `nota`: nota final menor a la mínima aprobatoria (sin nota no bloquea, como antes).
 *  - `deuda`: la obligación vigente de la matrícula tiene saldo sin confirmar. Un pago pendiente de confirmar no cuenta como pagado.
 *  - `acta`: solo si `academico.certificado_exige_acta_cerrada=true` (apagado por defecto): la edición debe tener el acta cerrada.
 *
 * Un administrador puede emitir pese a los bloqueos con un motivo (queda en el certificado y en la auditoría).
 *
 * @phpstan-type Bloqueo array{codigo: string, mensaje: string}
 */
class ElegibilidadCertificado
{
    public function __construct(private readonly GestionPagos $pagos) {}

    /**
     * Bloqueos para una matrícula ya conocida su asistencia y nota (el lote ya las tiene calculadas).
     *
     * @return list<Bloqueo>
     */
    public function bloqueos(Matricula $matricula, ?float $asistencia, ?float $nota): array
    {
        $bloqueos = [];
        $minimaAsistencia = Entrada::entero(config('academico.asistencia_minima', 80));
        $minimaNota = Entrada::decimal(config('academico.nota_minima', 11));

        if ($asistencia !== null && $asistencia < $minimaAsistencia) {
            $bloqueos[] = ['codigo' => 'asistencia', 'mensaje' => sprintf('La asistencia registrada (%.1f%%) es menor al mínimo requerido (%d%%).', $asistencia, $minimaAsistencia)];
        }
        if ($nota !== null && $nota < $minimaNota) {
            $bloqueos[] = ['codigo' => 'nota', 'mensaje' => sprintf('La nota final (%.2f) es menor a la nota mínima aprobatoria (%.0f).', $nota, $minimaNota)];
        }

        $saldo = $this->saldoPendiente($matricula);
        if ($saldo > 0) {
            $bloqueos[] = ['codigo' => 'deuda', 'mensaje' => 'La matrícula tiene un saldo pendiente de S/ '.GestionPagos::formato($saldo).' en su obligación de pago.'];
        }

        if (config('academico.certificado_exige_acta_cerrada') === true && $matricula->convocatoria?->estado !== 'cerrada') {
            $bloqueos[] = ['codigo' => 'acta', 'mensaje' => 'El acta de la edición todavía no está cerrada.'];
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
