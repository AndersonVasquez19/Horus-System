<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PaginasServicios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServicioTecnologico extends Model
{
    protected $table = 'servicios_tecnologicos';

    protected $fillable = ['nombre', 'categoria', 'estado'];

    /** @var list<string> */
    protected $appends = ['pagina_publica'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    /** @return HasOne<ServicioPublicacion, $this> */
    public function publicacion(): HasOne
    {
        return $this->hasOne(ServicioPublicacion::class);
    }

    public function etag(): string
    {
        return '"servicio-'.$this->id.'-v'.$this->revision.'"';
    }

    /**
     * Página pública administrable asociada por código estable (null si el servicio no es una de las tres).
     *
     * @return array{codigo: string, etiqueta: string, ruta: string, url: string}|null
     */
    public function getPaginaPublicaAttribute(): ?array
    {
        return PaginasServicios::de($this->codigo);
    }
}
