<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de clase de una edición. `fecha` es un texto `Y-m-d` (sin cast de fecha: así se compara igual en SQLite y PostgreSQL) y
 * las horas se guardan como `HH:MM:SS`. Una sesión solo lleva fecha, horas y tema: ni docente ni lugar (la gestión académica no es un portal de clases).
 */
class SesionClase extends Model
{
    public const ESTADOS = ['programada', 'realizada', 'cancelada'];

    protected $table = 'sesiones_clase';

    protected $fillable = ['tema'];

    protected $hidden = ['id', 'convocatoria_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'numero' => 'integer'];
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function etag(): string
    {
        return '"sesion-'.$this->identificador.'-v'.$this->revision.'"';
    }
}
