<?php

namespace App\Models;

use App\Observers\MatriculaNotificationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** @property Carbon $fecha_matricula Fecha convertida por el cast date. */
#[ObservedBy([MatriculaNotificationObserver::class])]
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

    /** @return BelongsTo<Estudiante, $this> */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class)->withTrashed();
    }

    /** @return BelongsTo<Curso, $this> */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class)->withTrashed();
    }
}
