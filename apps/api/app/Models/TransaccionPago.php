<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila del libro de pagos. Inmutable: solo un pago `pendiente` pasa a `confirmada` o `rechazada`; un reverso es otra fila. La
 * referencia y los motivos son texto libre y no se copian a la auditoría.
 *
 * @property string $monto Importe como texto decimal (nunca `float`).
 * @property \Illuminate\Support\Carbon|string|null $resuelto_en
 */
class TransaccionPago extends Model
{
    protected $table = 'transacciones_pago';

    protected $guarded = [];

    protected $hidden = ['id', 'obligacion_pago_id', 'metodo_pago_id', 'reversa_de_id', 'clave_idempotencia', 'huella', 'registrado_por', 'resuelto_por'];

    /** @return BelongsTo<ObligacionPago, $this> */
    public function obligacion(): BelongsTo
    {
        return $this->belongsTo(ObligacionPago::class, 'obligacion_pago_id');
    }

    /** @return BelongsTo<MetodoPago, $this> */
    public function metodo(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<ComprobantePago, $this> */
    public function comprobante(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ComprobantePago::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversa_de_id');
    }
}
