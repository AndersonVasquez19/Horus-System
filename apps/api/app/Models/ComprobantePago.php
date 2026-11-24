<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Captura del comprobante de un pago. La ruta nunca sale por la API: el archivo solo se sirve por el endpoint autenticado. */
class ComprobantePago extends Model
{
    protected $table = 'comprobantes_pago';

    /** @var list<string> */
    protected $fillable = ['transaccion_pago_id', 'ruta', 'mime', 'tamano', 'sha256', 'subido_por'];

    protected $hidden = ['id', 'transaccion_pago_id', 'ruta', 'subido_por'];
}
