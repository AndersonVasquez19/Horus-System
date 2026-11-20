<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagos: etiquetas de CÓMO pagó la persona, además de Yape. No son pasarelas ni integraciones: el pago ocurre fuera del sistema y
 * aquí solo se guarda la constancia. Se añaden Plin, transferencia, depósito y efectivo.
 *
 * Solo inserta las que faltan (el código es único: repetir la migración no duplica nada) y no toca `yape` ni ninguna transacción
 * existente. `down()` retira únicamente las que ningún pago usa, para no romper el libro.
 */
return new class extends Migration
{
    private const METODOS = [
        'plin' => 'Plin',
        'transferencia' => 'Transferencia bancaria',
        'deposito' => 'Depósito bancario',
        'efectivo' => 'Efectivo',
    ];

    public function up(): void
    {
        foreach (self::METODOS as $codigo => $nombre) {
            DB::table('metodos_pago')->insertOrIgnore(['codigo' => $codigo, 'nombre' => $nombre, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $usados = DB::table('transacciones_pago')->distinct()->pluck('metodo_pago_id')->all();
        DB::table('metodos_pago')->whereIn('codigo', array_keys(self::METODOS))->whereNotIn('id', $usados)->delete();
    }
};
