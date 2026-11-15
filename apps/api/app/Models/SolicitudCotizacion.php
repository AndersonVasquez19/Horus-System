<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudCotizacion extends Model
{
    protected $table = 'solicitudes_cotizacion';

    protected $fillable = ['necesidad_cliente', 'contacto_nombre', 'contacto_email', 'contacto_telefono'];

    protected $hidden = ['creador_ambito', 'creacion_clave_hash', 'creacion_huella'];

    protected $casts = ['revision' => 'integer', 'recibida_at' => 'datetime',
        'ultimo_seguimiento_at' => 'datetime', 'proximo_seguimiento' => 'date'];

    public function etag(): string
    {
        return '"cotizacion-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
