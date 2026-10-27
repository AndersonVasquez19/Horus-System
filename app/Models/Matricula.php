<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Matricula extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'estudiante_id',
        'curso_id',
        'fecha_matricula',
        'estado',
        'monto_pagado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_matricula' => 'date',
            'monto_pagado' => 'decimal:2',
        ];
    }

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class)->withTrashed();
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class)->withTrashed();
    }
}
