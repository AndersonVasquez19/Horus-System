<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $vigente_desde
 * @property Carbon $vigente_hasta
 * @property Carbon|null $autorizado_at
 */
class ServicioPublicacion extends Model
{
    protected $table = 'servicio_publicaciones';

    protected $fillable = ['titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta'];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date:Y-m-d', 'vigente_hasta' => 'date:Y-m-d', 'autorizado_at' => 'datetime'];
    }
}
