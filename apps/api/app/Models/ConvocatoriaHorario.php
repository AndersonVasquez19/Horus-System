<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque de horario de una convocatoria: día de la semana (1 lunes a 7 domingo), hora de inicio y de fin. No tiene revisión propia: se sustituyen todos juntos y avanza la
 * de la convocatoria.
 */
class ConvocatoriaHorario extends Model
{
    protected $table = 'convocatoria_horarios';

    protected $fillable = ['dia_semana', 'hora_inicio', 'hora_fin'];

    protected $hidden = ['id'];

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}
