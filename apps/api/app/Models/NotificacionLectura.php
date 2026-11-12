<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lectura de una notificación general por un usuario concreto (las personales usan `leida` en su fila).
 *
 * @property int $notificacion_id
 * @property int $user_id
 */
class NotificacionLectura extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'notificacion_lecturas';

    protected $fillable = ['notificacion_id', 'user_id', 'leida_at'];

    protected $casts = ['leida_at' => 'datetime'];

    /** @return BelongsTo<NotificacionInterna, $this> */
    public function notificacion(): BelongsTo
    {
        return $this->belongsTo(NotificacionInterna::class, 'notificacion_id');
    }
}
