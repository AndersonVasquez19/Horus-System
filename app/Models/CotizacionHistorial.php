<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CotizacionHistorial extends Model
{
    protected $table = 'cotizacion_historial';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['actor_ambito', 'clave_hash', 'huella'];

    protected $casts = ['metadatos' => 'array', 'revision' => 'integer', 'created_at' => 'datetime'];
}
