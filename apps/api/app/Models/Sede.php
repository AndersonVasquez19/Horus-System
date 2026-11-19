<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sede (local físico). El `id` interno no sale por la API; el identificador público es el UUID. No se elimina: se desactiva.
 * Código, estado e identificador los fija el servicio (no son asignables en masa).
 */
class Sede extends Model
{
    public const ESTADOS = ['activo', 'inactivo'];

    protected $table = 'sedes';

    protected $fillable = ['nombre', 'direccion', 'ciudad'];

    protected $hidden = ['id'];

    /** @return HasMany<Aula, $this> */
    public function aulas(): HasMany
    {
        return $this->hasMany(Aula::class);
    }

    /** @return HasMany<Convocatoria, $this> */
    public function convocatorias(): HasMany
    {
        return $this->hasMany(Convocatoria::class);
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activo';
    }
}
