<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Historial de solo inserción de una reclamación (evidencia del caso). */
class ReclamacionHistorial extends Model
{
    protected $table = 'reclamacion_historial';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['metadatos' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('El historial es de solo inserción.');
        });
        static::deleting(function (): void {
            throw new LogicException('El historial es de solo inserción.');
        });
    }

    /** @return BelongsTo<Reclamacion, $this> */
    public function reclamacion(): BelongsTo
    {
        return $this->belongsTo(Reclamacion::class, 'reclamacion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
