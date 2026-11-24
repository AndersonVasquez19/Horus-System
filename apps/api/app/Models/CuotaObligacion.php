<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cuota de una obligación (el pago completo es una sola). Solo la crea `GestionPagos`. */
class CuotaObligacion extends Model
{
    protected $table = 'cuotas_obligacion';

    /** @var list<string> */
    protected $fillable = ['obligacion_pago_id', 'numero', 'monto', 'vence'];

    protected $hidden = ['id', 'obligacion_pago_id'];
}
