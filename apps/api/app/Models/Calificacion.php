<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota (escala vigesimal, 0 a 20) de una matrícula en una evaluación; una sola por pareja. Es un dato personal del
 * estudiante: la API devuelve solo lo necesario por pantalla y la auditoría guarda valores, nunca nombres.
 */
class Calificacion extends Model
{
    protected $table = 'calificaciones';

    /** Escala vigesimal. */
    public const NOTA_MINIMA = 0;

    public const NOTA_MAXIMA = 20;

    /** Nota final desde la que se aprueba (pendiente de hacerla configurable en `system_settings`). */
    public const NOTA_APROBATORIA = 11;

    protected $fillable = ['nota', 'observacion'];

    protected function casts(): array
    {
        return ['nota' => 'decimal:2'];
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class)->withTrashed();
    }

    /** @return BelongsTo<Evaluacion, $this> */
    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class);
    }
}
