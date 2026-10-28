<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionInterna extends Model
{
    protected $table = 'notificaciones_internas';

    protected $fillable = [
        'user_id',
        'titulo',
        'mensaje',
        'tipo',
        'leida',
        'vinculo',
    ];

    protected $casts = [
        'leida' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
