<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Método de pago del catálogo (hoy solo `yape`). */
class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    protected $guarded = [];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
