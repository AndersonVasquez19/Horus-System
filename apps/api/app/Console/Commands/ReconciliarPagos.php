<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Services\GestionPagos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Alinea `matriculas` (`monto_pagado`, `estado`) con el libro de pagos, con seguridad:
 *
 *  - **Sin opciones: simulación.** No escribe nada; informa las discrepancias (y las matrículas con monto o estado `pagada` sin obligación
 *    vigente, que no se tocan).
 *  - `--informe=ruta.csv`: además guarda el detalle completo en un CSV (solo ids y valores, sin datos personales).
 *  - `--aplicar --confirmo`: escribe. Antes guarda el valor anterior de cada matrícula en `matriculas_monto_respaldo` (lote nuevo),
 *    todo en una transacción, y deja un registro en la auditoría. Exige haber corrido la migración 000088.
 *  - `--revertir=<lote>`: restaura los valores anteriores de un lote (solo las filas que no se hayan revertido ya).
 */
final class ReconciliarPagos extends Command
{
    protected $signature = 'horus:reconciliar-pagos
        {--aplicar : Aplica la alineación (exige --confirmo)}
        {--confirmo : Confirma que se revisó el informe y que hay un respaldo de la base}
        {--informe= : Guarda el detalle de discrepancias en un CSV}
        {--revertir= : Restaura los valores anteriores de un lote aplicado}';

    protected $description = 'Informa (simulación por defecto) y opcionalmente alinea discrepancias entre matriculas y el libro de pagos, con respaldo.';

    public function handle(GestionPagos $pagos): int
    {
        $lote = (string) ($this->option('revertir') ?? '');
        if ($lote !== '') {
            return $this->revertir($lote);
        }

        $aplicar = (bool) $this->option('aplicar');
        if ($aplicar && ! $this->option('confirmo')) {
            $this->error('Para aplicar hace falta --confirmo (revisa antes el informe de la simulación y respalda la base).');

            return self::FAILURE;
        }
        if ($aplicar && ! Schema::hasTable('matriculas_monto_respaldo')) {
            $this->error('Falta la tabla matriculas_monto_respaldo: ejecuta antes la migración 2026_11_19_000088.');

            return self::FAILURE;
        }

        [$desalineadas, $sinLibroConPago] = $this->analizar($pagos);

        $this->line($aplicar ? 'MODO APLICAR: se alinearán las matrículas con obligación vigente.' : 'SIMULACIÓN: no se escribió nada.');
        $this->line('Discrepancias con obligación vigente: '.count($desalineadas));
        $this->line('Matrículas pagadas o con monto sin obligación vigente (no se tocan): '.count($sinLibroConPago));

        foreach (array_slice($desalineadas, 0, 20) as $d) {
            $this->line("  #{$d['matricula_id']}: estado {$d['estado_actual']} -> {$d['estado_esperado']}, monto {$d['monto_actual']} -> {$d['monto_esperado']}");
        }
        foreach (array_slice($sinLibroConPago, 0, 20) as $s) {
            $this->line("  #{$s['matricula_id']} (sin libro): estado {$s['estado']}, monto {$s['monto_pagado']}");
        }
        if (count($desalineadas) > 20 || count($sinLibroConPago) > 20) {
            $this->line('  … (usa --informe=ruta.csv para el detalle completo)');
        }

        $informe = (string) ($this->option('informe') ?? '');
        if ($informe !== '') {
            $this->guardarInforme($informe, $desalineadas, $sinLibroConPago);
        }

        if (! $aplicar || $desalineadas === []) {
            return self::SUCCESS;
        }

        $nuevoLote = (string) Str::uuid();
        DB::transaction(function () use ($desalineadas, $nuevoLote): void {
            foreach ($desalineadas as $d) {
                DB::table('matriculas_monto_respaldo')->insert([
                    'lote' => $nuevoLote,
                    'matricula_id' => $d['matricula_id'],
                    'monto_pagado_anterior' => $d['monto_actual'] === 'null' ? null : $d['monto_actual'],
                    'estado_anterior' => $d['estado_actual'],
                    'monto_pagado_nuevo' => $d['monto_esperado'],
                    'estado_nuevo' => $d['estado_esperado'],
                ]);
                DB::table('matriculas')->where('id', $d['matricula_id'])->update([
                    'monto_pagado' => $d['monto_esperado'],
                    'estado' => $d['estado_esperado'],
                ]);
            }
            AuditLog::record('matriculas_reconciliadas', Matricula::class, null, ['lote' => $nuevoLote, 'cantidad' => count($desalineadas)], null);
        });

        $this->info('Alineación aplicada sobre '.count($desalineadas)." matrícula(s). Lote: {$nuevoLote} (revertir con --revertir={$nuevoLote}).");

        return self::SUCCESS;
    }

    /** @return array{0: list<array{matricula_id: int, estado_actual: string, estado_esperado: string, monto_actual: string, monto_esperado: string}>, 1: list<array{matricula_id: int, estado: string, monto_pagado: string}>} */
    private function analizar(GestionPagos $pagos): array
    {
        $obligacionesVigentes = ObligacionPago::query()
            ->where('estado', 'vigente')
            ->with('transacciones')
            ->get()
            ->keyBy('matricula_id');

        $desalineadas = [];
        $sinLibroConPago = [];

        Matricula::withTrashed()->orderBy('id')->chunkById(200, function ($lote) use ($obligacionesVigentes, $pagos, &$desalineadas, &$sinLibroConPago): void {
            foreach ($lote as $m) {
                $ob = $obligacionesVigentes->get($m->id);
                $actualCentimos = $m->monto_pagado === null ? 0 : GestionPagos::leer($m->monto_pagado);

                if ($ob === null) {
                    if ($actualCentimos > 0 || $m->estado === 'pagada') {
                        $sinLibroConPago[] = [
                            'matricula_id' => $m->id,
                            'estado' => $m->estado,
                            'monto_pagado' => GestionPagos::formato($actualCentimos),
                        ];
                    }

                    continue;
                }

                $saldos = $pagos->saldos($ob);
                $esperadoMonto = $saldos['confirmado'];
                $esperadoEstado = $m->estado === 'anulada'
                    ? 'anulada'
                    : ($esperadoMonto >= $saldos['total'] ? 'pagada' : 'pendiente');

                if ($actualCentimos !== $esperadoMonto || $m->monto_pagado === null || $m->estado !== $esperadoEstado) {
                    $desalineadas[] = [
                        'matricula_id' => $m->id,
                        'estado_actual' => $m->estado,
                        'estado_esperado' => $esperadoEstado,
                        'monto_actual' => $m->monto_pagado === null ? 'null' : GestionPagos::formato($actualCentimos),
                        'monto_esperado' => GestionPagos::formato($esperadoMonto),
                    ];
                }
            }
        });

        return [$desalineadas, $sinLibroConPago];
    }

    /**
     * @param  list<array{matricula_id: int, estado_actual: string, estado_esperado: string, monto_actual: string, monto_esperado: string}>  $desalineadas
     * @param  list<array{matricula_id: int, estado: string, monto_pagado: string}>  $sinLibroConPago
     */
    private function guardarInforme(string $ruta, array $desalineadas, array $sinLibroConPago): void
    {
        $manejador = @fopen($ruta, 'wb');
        if ($manejador === false) {
            $this->warn("No se pudo escribir el informe en {$ruta}.");

            return;
        }
        fputcsv($manejador, ['tipo', 'matricula_id', 'estado_actual', 'estado_esperado', 'monto_actual', 'monto_esperado'], ',', '"', '\\');
        foreach ($desalineadas as $d) {
            fputcsv($manejador, ['discrepancia', $d['matricula_id'], $d['estado_actual'], $d['estado_esperado'], $d['monto_actual'], $d['monto_esperado']], ',', '"', '\\');
        }
        foreach ($sinLibroConPago as $s) {
            fputcsv($manejador, ['sin_libro', $s['matricula_id'], $s['estado'], '', $s['monto_pagado'], ''], ',', '"', '\\');
        }
        fclose($manejador);
        $this->line("Informe guardado en {$ruta}.");
    }

    private function revertir(string $lote): int
    {
        if (! Schema::hasTable('matriculas_monto_respaldo')) {
            $this->error('No existe la tabla matriculas_monto_respaldo.');

            return self::FAILURE;
        }

        $filas = DB::table('matriculas_monto_respaldo')->where('lote', $lote)->whereNull('revertido_at')->get();
        if ($filas->isEmpty()) {
            $this->warn('No hay filas pendientes de revertir en ese lote.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($filas, $lote): void {
            foreach ($filas as $f) {
                DB::table('matriculas')->where('id', $f->matricula_id)->update([
                    'monto_pagado' => $f->monto_pagado_anterior,
                    'estado' => $f->estado_anterior,
                ]);
                DB::table('matriculas_monto_respaldo')->where('id', $f->id)->update(['revertido_at' => now()]);
            }
            AuditLog::record('matriculas_reconciliacion_revertida', Matricula::class, null, ['lote' => $lote, 'cantidad' => $filas->count()], null);
        });

        $this->info('Se restauraron '.$filas->count().' matrícula(s) del lote '.$lote.'.');

        return self::SUCCESS;
    }
}
