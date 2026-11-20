<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CuotaObligacion;
use App\Models\ObligacionPago;
use App\Services\GestionPagos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verificación de solo lectura del libro de pagos: no escribe nada. Termina con código 1 si encuentra inconsistencias. Revisa, por
 * obligación: suma de cuotas = total; asignaciones por cuota entre 0 y el monto de la cuota; asignaciones netas = confirmado neto;
 * `matriculas.monto_pagado` = confirmado neto (obligaciones vigentes); sin sobrepago; un solo reverso por pago.
 * Solo imprime identificadores y descripciones de la regla, nunca datos de personas.
 */
class VerificarFinanzas extends Command
{
    protected $signature = 'horus:verificar-finanzas';

    protected $description = 'Verifica la integridad del libro de pagos (solo lectura).';

    public function handle(GestionPagos $pagos): int
    {
        $problemas = [];
        foreach (ObligacionPago::query()->orderBy('id')->cursor() as $o) {
            $id = $o->identificador;
            $total = GestionPagos::leer($o->monto_total);
            $cuotas = CuotaObligacion::query()->where('obligacion_pago_id', $o->id)->get();
            if ($cuotas->isEmpty()) {
                $problemas[] = "{$id}: sin cuotas";
            }
            if ($cuotas->sum(fn ($c): int => GestionPagos::leer($c->monto)) !== $total) {
                $problemas[] = "{$id}: la suma de cuotas no iguala el total";
            }

            $porCuota = $pagos->pagadoPorCuota($o);
            foreach ($cuotas as $c) {
                $p = $porCuota[$c->id] ?? 0;
                if ($p < 0 || $p > GestionPagos::leer($c->monto)) {
                    $problemas[] = "{$id}: la cuota {$c->numero} tiene asignaciones fuera de rango";
                }
            }

            $s = $pagos->saldos($o);
            if (array_sum($porCuota) !== $s['confirmado']) {
                $problemas[] = "{$id}: las asignaciones no coinciden con lo confirmado";
            }
            if ($s['confirmado'] > $total || $s['confirmado'] < 0) {
                $problemas[] = "{$id}: sobrepago o saldo negativo";
            }
            if ($o->estado === 'vigente') {
                $mp = DB::table('matriculas')->where('id', $o->matricula_id)->value('monto_pagado');
                if (GestionPagos::leer($mp) !== $s['confirmado']) {
                    $problemas[] = "{$id}: monto_pagado de la matrícula no coincide con el libro";
                }
            }
        }

        $gruposDuplicados = DB::table('transacciones_pago')->select('reversa_de_id')->whereNotNull('reversa_de_id')->groupBy('reversa_de_id')->havingRaw('count(*) > 1')->get();
        $reversosDuplicados = count($gruposDuplicados);
        if ($reversosDuplicados > 0) {
            $problemas[] = "{$reversosDuplicados} pago(s) con más de un reverso";
        }

        if ($problemas === []) {
            $this->info('Libro de pagos consistente.');

            return self::SUCCESS;
        }
        foreach ($problemas as $p) {
            $this->error($p);
        }

        return self::FAILURE;
    }
}
