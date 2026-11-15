<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $fecha_cierre_estimada Fecha convertida por el cast `date`.
 * @property Carbon|null $cerrada_at Momento en que pasó a `ganada` o `perdida`.
 *
 * Oportunidad del pipeline comercial. El `id` interno no sale por la API. Los campos asignables en masa son los que
 * edita el panel; etapa, motivo, vínculos, cierre y revisión los fijan los servicios.
 */
class Oportunidad extends Model
{
    use SoftDeletes;

    protected $table = 'oportunidades';

    public const ETAPAS = ['nueva', 'calificada', 'propuesta', 'negociacion', 'ganada', 'perdida'];

    public const ABIERTAS = ['nueva', 'calificada', 'propuesta', 'negociacion'];

    public const CERRADAS = ['ganada', 'perdida'];

    /** Probabilidad inicial al entrar en cada etapa (editable mientras la oportunidad esté abierta). */
    public const PROBABILIDAD = ['nueva' => 10, 'calificada' => 25, 'propuesta' => 50, 'negociacion' => 75, 'ganada' => 100, 'perdida' => 0];

    /**
     * Transiciones que se piden con `etapa`: se avanza de a una etapa y se pierde desde cualquiera abierta. Una cerrada
     * solo se reabre (a `nueva`) y el cambio queda en el historial.
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'nueva' => ['calificada', 'perdida'],
        'calificada' => ['propuesta', 'perdida'],
        'propuesta' => ['negociacion', 'perdida'],
        'negociacion' => ['ganada', 'perdida'],
        'ganada' => ['nueva'],
        'perdida' => ['nueva'],
    ];

    protected $fillable = ['titulo', 'monto_estimado', 'probabilidad', 'fecha_cierre_estimada'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'probabilidad' => 'integer',
            'monto_estimado' => 'decimal:2',
            'fecha_cierre_estimada' => 'date',
            'cerrada_at' => 'datetime',
        ];
    }

    public function estaAbierta(): bool
    {
        return in_array($this->etapa, self::ABIERTAS, true);
    }

    public function etag(): string
    {
        return '"oportunidad-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<Prospecto, $this> */
    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(Prospecto::class);
    }

    /** @return BelongsTo<ServicioTecnologico, $this> */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(ServicioTecnologico::class, 'servicio_id');
    }

    /** @return BelongsTo<SolicitudCotizacion, $this> */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(SolicitudCotizacion::class, 'cotizacion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return HasMany<OportunidadHistorial, $this> */
    public function historial(): HasMany
    {
        return $this->hasMany(OportunidadHistorial::class)->orderByDesc('id');
    }
}
