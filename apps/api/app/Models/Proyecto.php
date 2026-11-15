<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin_estimada
 * @property Carbon|null $fecha_fin_real
 *
 * Proyecto del panel de servicios. El `id` interno no sale por la API. Los campos asignables en masa son los que edita
 * el panel; código, estado, vínculos, fecha de fin real y revisión los fijan los servicios.
 */
class Proyecto extends Model
{
    use SoftDeletes;

    public const ESTADOS = ['planificado', 'en_curso', 'pausado', 'completado', 'cancelado'];

    public const ACTIVOS = ['planificado', 'en_curso', 'pausado'];

    /** Máximo de hitos por proyecto. */
    public const MAX_HITOS = 50;

    /**
     * Transiciones que se piden con `estado`. `completado` y `cancelado` son terminales.
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'planificado' => ['en_curso', 'cancelado'],
        'en_curso' => ['completado', 'pausado', 'cancelado'],
        'pausado' => ['en_curso', 'cancelado'],
        'completado' => [],
        'cancelado' => [],
    ];

    protected $fillable = ['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'presupuesto' => 'decimal:2',
            'fecha_inicio' => 'date',
            'fecha_fin_estimada' => 'date',
            'fecha_fin_real' => 'date',
        ];
    }

    public function esActivo(): bool
    {
        return in_array($this->estado, self::ACTIVOS, true);
    }

    public function etag(): string
    {
        return '"proyecto-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<Oportunidad, $this> */
    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return HasMany<ProyectoHito, $this> */
    public function hitos(): HasMany
    {
        return $this->hasMany(ProyectoHito::class)->orderBy('orden')->orderBy('id');
    }
}
