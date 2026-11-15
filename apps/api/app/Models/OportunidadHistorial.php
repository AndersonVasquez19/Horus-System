<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por cambio de etapa de una oportunidad (alta incluida). Solo etapas y autor: sin textos libres.
 */
class OportunidadHistorial extends Model
{
    protected $table = 'oportunidad_historial';

    public const UPDATED_AT = null;

    protected $hidden = ['id'];

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
