<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ImagenesServicios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarjeta de una ficha editorial. Las rutas de imagen las fija solo el servidor (no son asignables en masa).
 *
 * @property int $id
 * @property int $servicio_publicacion_id
 * @property list<string>|null $caracteristicas
 * @property string|null $imagen_ruta
 * @property int $orden
 * @property bool $activo
 */
class ServicioPublicacionElemento extends Model
{
    protected $table = 'servicio_publicacion_elementos';

    protected $fillable = ['titulo', 'descripcion', 'caracteristicas', 'icono', 'variante_imagen', 'imagen_alt', 'orden', 'activo'];

    /** @var list<string> */
    protected $appends = ['imagen_url'];

    protected function casts(): array
    {
        return ['caracteristicas' => 'array', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    /** @return BelongsTo<ServicioPublicacion, $this> */
    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(ServicioPublicacion::class, 'servicio_publicacion_id');
    }

    public function getImagenUrlAttribute(): ?string
    {
        return ImagenesServicios::url($this->imagen_ruta);
    }
}
