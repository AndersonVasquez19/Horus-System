<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota de un ticket. Solo se crea: una vez guardada es de solo lectura (se corrige añadiendo otra). Las internas no
 * salen de la API de soporte.
 */
class TicketNota extends Model
{
    protected $table = 'ticket_notas';

    public const UPDATED_AT = null;

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['interna' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
