<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ImagenesServicios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $vigente_desde
 * @property Carbon $vigente_hasta
 * @property Carbon|null $autorizado_at
 * @property string|null $imagen_principal_ruta
 */
class ServicioPublicacion extends Model
{
    protected $table = 'servicio_publicaciones';

    protected $fillable = ['titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta', 'titulo_tarjetas', 'imagen_principal_alt'];

    /** @var list<string> */
    protected $appends = ['imagen_principal_url'];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date:Y-m-d', 'vigente_hasta' => 'date:Y-m-d', 'autorizado_at' => 'datetime'];
    }

    /** @return HasMany<ServicioPublicacionElemento, $this> */
    public function elementos(): HasMany
    {
        return $this->hasMany(ServicioPublicacionElemento::class)->orderBy('orden')->orderBy('id');
    }

    public function getImagenPrincipalUrlAttribute(): ?string
    {
        return ImagenesServicios::url($this->imagen_principal_ruta);
    }
}
