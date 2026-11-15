<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente del panel de servicios (persona o empresa). El `id` interno no sale por la API: se usa `identificador`.
 * Los campos asignables en masa son los que edita el panel; `estado`, `revision` y `responsable_id` los fija el servicio.
 */
class Cliente extends Model
{
    use SoftDeletes;

    public const TIPOS = ['persona', 'empresa'];

    public const ESTADOS = ['activo', 'inactivo'];

    public const DOCUMENTOS = ['dni', 'ruc', 'ce', 'otro'];

    protected $fillable = ['tipo', 'nombre', 'documento_tipo', 'documento_numero', 'email', 'telefono', 'direccion'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function etag(): string
    {
        return '"cliente-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return HasMany<ClienteContacto, $this> */
    public function contactos(): HasMany
    {
        return $this->hasMany(ClienteContacto::class)->orderByDesc('principal')->orderBy('id');
    }
}
