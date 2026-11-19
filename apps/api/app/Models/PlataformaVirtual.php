<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Herramienta externa de clases virtuales (Meet, Zoom…). Solo se cataloga: no guarda credenciales, claves de API ni enlaces
 * (los enlaces de las sesiones son privados y llegan en una fase posterior).
 */
class PlataformaVirtual extends Model
{
    public const TIPOS = ['meet', 'zoom', 'teams', 'otra'];

    protected $table = 'plataformas_virtuales';

    protected $fillable = ['nombre', 'tipo'];

    protected $hidden = ['id'];

    /** @return HasMany<Convocatoria, $this> */
    public function convocatorias(): HasMany
    {
        return $this->hasMany(Convocatoria::class, 'plataforma_virtual_id');
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activo';
    }
}
