<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bandeja de salida y evidencia de lo enviado al consumidor. `cuerpo` es el texto exacto remitido o imprimible.
 * Sin destinatario propio: se usa el correo o domicilio de la reclamación.
 */
class ReclamacionEnvio extends Model
{
    protected $table = 'reclamacion_envios';

    protected $guarded = ['id'];

    protected $casts = ['enviado_at' => 'datetime', 'intentos' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Los envíos son evidencia y no se eliminan.');
        });
    }

    /** @return BelongsTo<Reclamacion, $this> */
    public function reclamacion(): BelongsTo
    {
        return $this->belongsTo(Reclamacion::class, 'reclamacion_id');
    }
}
