<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GestionPagos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Simulación del saldo inicial de pagos: informa cuántas matrículas con edición ya tienen `monto_pagado` > 0 pero ninguna obligación
 * vigente en el libro. SOLO LEE: no crea obligaciones ni transacciones ni modifica `monto_pagado`. Cargar ese saldo en el libro exige
 * una decisión y un comando aparte, con autorización.
 */
class SaldoInicialPagos extends Command
{
    protected $signature = 'horus:saldo-inicial-pagos';

    protected $description = 'Simula (solo lectura) el saldo inicial de pagos de las matrículas existentes.';

    public function handle(): int
    {
        $sinLibro = DB::table('matriculas as m')
            ->whereNull('m.deleted_at')->where('m.estado', '!=', 'anulada')->whereNotNull('m.convocatoria_id')->where('m.monto_pagado', '>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('obligaciones_pago as o')->whereColumn('o.matricula_id', 'm.id')->where('o.estado', 'vigente'))
            ->get(['m.id', 'm.monto_pagado']);

        $total = 0;
        foreach ($sinLibro as $m) {
            $total += GestionPagos::leer($m->monto_pagado);
        }

        $this->info('SIMULACIÓN: no se escribió nada.');
        $this->line('Matrículas con monto_pagado y sin obligación en el libro: '.$sinLibro->count());
        $this->line('Suma de monto_pagado de esas matrículas (PEN): '.GestionPagos::formato($total));
        foreach ($sinLibro->take(20) as $m) {
            $this->line('  matrícula #'.$m->id.': '.GestionPagos::formato(GestionPagos::leer($m->monto_pagado)));
        }
        if ($sinLibro->count() > 20) {
            $this->line('  … y '.($sinLibro->count() - 20).' más');
        }

        return self::SUCCESS;
    }
}
