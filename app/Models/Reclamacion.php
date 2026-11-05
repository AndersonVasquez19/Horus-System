<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\GestionReclamaciones;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Reclamación o queja del Libro de Reclamaciones. Los datos del consumidor son inmutables y el registro
 * no se elimina (conservación legal): solo cambian los campos de gestión listados en {@see self::MUTABLES}.
 * Se crea y modifica únicamente a través de {@see GestionReclamaciones}.
 */
class Reclamacion extends Model
{
    protected $table = 'reclamaciones';

    /** Sin asignación masiva: el servicio usa forceFill. */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['token_envio'];

    /** Columnas que pueden cambiar después de la creación. @var list<string> */
    public const MUTABLES = ['estado', 'tipo', 'fecha_limite', 'suspension_desde', 'suspension_hasta', 'responsable_id', 'respuesta', 'respondida_at', 'updated_at'];

    protected $casts = [
        'recibida_at' => 'datetime',
        'informado_at' => 'datetime',
        'respondida_at' => 'datetime',
        'fecha_limite' => 'date',
        'suspension_desde' => 'date',
        'suspension_hasta' => 'date',
        'es_menor' => 'boolean',
        'monto_bien' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $reclamacion): void {
            $prohibidos = array_diff(array_keys($reclamacion->getDirty()), self::MUTABLES);
            if ($prohibidos !== []) {
                throw new LogicException('Los datos de la reclamación no se editan: '.implode(', ', $prohibidos));
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Las reclamaciones no se eliminan (conservación legal).');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'identificador';
    }

    /** @return HasMany<ReclamacionHistorial, $this> */
    public function historial(): HasMany
    {
        return $this->hasMany(ReclamacionHistorial::class, 'reclamacion_id')->orderBy('id');
    }

    /** @return HasMany<ReclamacionEnvio, $this> */
    public function envios(): HasMany
    {
        return $this->hasMany(ReclamacionEnvio::class, 'reclamacion_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
