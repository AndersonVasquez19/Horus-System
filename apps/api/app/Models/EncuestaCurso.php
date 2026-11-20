<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encuesta post-clase asociada a una matrícula (3 niveles de satisfacción + sugerencia de próximo curso).
 *
 * @property int $id
 * @property string $identificador
 * @property int $matricula_id
 * @property int $convocatoria_id
 * @property int|null $calificacion
 * @property string|null $sugerencia_curso
 * @property \Illuminate\Support\Carbon|null $respondida_at
 */
class EncuestaCurso extends Model
{
    protected $table = 'encuestas_curso';

    protected $fillable = [
        'calificacion',
        'sugerencia_curso',
    ];

    protected function casts(): array
    {
        return [
            'calificacion' => 'integer',
            'respondida_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}
