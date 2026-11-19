<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 *
 * Convocatoria: una edición abierta de un curso, con fechas, cupos y horario. El curso sigue siendo el catálogo. El `id`
 * interno no sale por la API. Los campos asignables en masa son los que edita el panel; código, estado, curso, cupos
 * disponibles y revisión los fijan los servicios.
 */
class Convocatoria extends Model
{
    use SoftDeletes;

    public const ESTADOS = ['borrador', 'abierta', 'en_curso', 'cerrada', 'cancelada'];

    /** Estados en los que todavía se edita y se puede matricular según el caso. */
    public const VIGENTES = ['borrador', 'abierta', 'en_curso'];

    /** Estados en los que se admite una matrícula nueva. */
    public const MATRICULABLES = ['abierta', 'en_curso'];

    /** Mismas modalidades que el catálogo de cursos (incluye las heredadas). */
    public const MODALIDADES = ['presencial', 'semipresencial', 'virtual', 'hibrido'];

    /** Modalidades de las ediciones NUEVAS. `semipresencial` e `hibrido` se conservan solo por compatibilidad histórica. */
    public const MODALIDADES_NUEVAS = ['presencial', 'virtual'];

    /** Máximo de bloques de horario por convocatoria. */
    public const MAX_HORARIOS = 20;

    /**
     * `cerrada` y `cancelada` son terminales y exigen `convocatorias.cerrar`.
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'borrador' => ['abierta', 'cancelada'],
        'abierta' => ['en_curso', 'cancelada'],
        'en_curso' => ['cerrada', 'cancelada'],
        'cerrada' => [],
        'cancelada' => [],
    ];

    protected $fillable = ['nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'cupos_maximos' => 'integer',
            'cupos_disponibles' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function estaVigente(): bool
    {
        return in_array($this->estado, self::VIGENTES, true);
    }

    public function cuposOcupados(): int
    {
        return $this->cupos_maximos - $this->cupos_disponibles;
    }

    public function etag(): string
    {
        return '"convocatoria-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<Curso, $this> */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class)->withTrashed();
    }

    /** @return HasMany<Evaluacion, $this> */
    public function evaluaciones(): HasMany
    {
        return $this->hasMany(Evaluacion::class)->orderBy('orden')->orderBy('id');
    }

    /** @return HasMany<ConvocatoriaHorario, $this> */
    public function horarios(): HasMany
    {
        return $this->hasMany(ConvocatoriaHorario::class)->orderBy('dia_semana')->orderBy('hora_inicio');
    }
}
