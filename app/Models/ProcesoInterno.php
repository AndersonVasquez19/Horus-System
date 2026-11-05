<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcesoInterno extends Model
{
    protected $table = 'procesos_internos';

    protected $fillable = [
        'titulo',
        'descripcion',
        'responsable_id',
        'estado',
        'prioridad',
        'fecha_vencimiento',
        'created_by',
        'observaciones',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
