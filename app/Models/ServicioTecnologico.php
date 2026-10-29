<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServicioTecnologico extends Model
{
    protected $table = 'servicios_tecnologicos';

    protected $fillable = ['nombre', 'categoria', 'estado'];

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
}
