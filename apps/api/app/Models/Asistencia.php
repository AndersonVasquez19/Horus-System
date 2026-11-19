<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asistencia de una matrícula a una sesión. Sesión, matrícula, autor y estado los fija el servicio; el motivo de corrección y la
 * observación son texto libre y nunca se copian a la auditoría.
 */
class Asistencia extends Model
{
    public const ESTADOS = ['presente', 'ausente', 'tardanza', 'justificada'];

    protected $table = 'asistencias';

    protected $hidden = ['id', 'sesion_clase_id', 'registrado_por'];

    /** @return BelongsTo<SesionClase, $this> */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionClase::class, 'sesion_clase_id');
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }
}
