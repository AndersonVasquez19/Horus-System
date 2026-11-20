<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $fecha
 *
 * Evaluación de una convocatoria con su peso porcentual. La suma de pesos de una convocatoria no pasa de 100 y debe ser
 * exactamente 100 para cerrar el acta (lo valida `GestionCalificaciones`). El `id` interno no sale por la API.
 */
class Evaluacion extends Model
{
    protected $table = 'evaluaciones';

    protected $fillable = ['nombre', 'peso', 'fecha'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['peso' => 'decimal:2', 'orden' => 'integer', 'fecha' => 'date'];
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class)->withTrashed();
    }

    /** @return HasMany<Calificacion, $this> */
    public function calificaciones(): HasMany
    {
        return $this->hasMany(Calificacion::class);
    }
}
