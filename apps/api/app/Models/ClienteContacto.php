<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Persona de contacto de un cliente. Se reemplazan todos juntos desde `GestionClientes`. */
class ClienteContacto extends Model
{
    protected $table = 'cliente_contactos';

    protected $fillable = ['nombre', 'cargo', 'email', 'telefono', 'principal'];

    protected $hidden = ['id', 'cliente_id'];

    protected function casts(): array
    {
        return ['principal' => 'boolean'];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
