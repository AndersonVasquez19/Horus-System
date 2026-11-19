<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de clase de una edición. `fecha` es un texto `Y-m-d` (sin cast de fecha: así se compara igual en SQLite y PostgreSQL) y
 * las horas se guardan como `HH:MM:SS`. El enlace de la clase virtual se guarda CIFRADO, nunca se serializa ni se asigna en masa:
 * solo `GestionSesiones::enlace()` lo descifra, con permiso `sesiones.ver-enlace` y auditoría.
 */
class SesionClase extends Model
{
    public const ESTADOS = ['programada', 'realizada', 'cancelada'];

    protected $table = 'sesiones_clase';

    protected $fillable = ['tema'];

    protected $hidden = ['id', 'convocatoria_id', 'docente_id', 'aula_id', 'plataforma_virtual_id', 'enlace_virtual'];

    protected function casts(): array
    {
        return ['enlace_virtual' => 'encrypted', 'revision' => 'integer', 'numero' => 'integer'];
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    /** @return BelongsTo<Docente, $this> */
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class)->withTrashed();
    }

    /** @return BelongsTo<Aula, $this> */
    public function aula(): BelongsTo
    {
        return $this->belongsTo(Aula::class);
    }

    /** @return BelongsTo<PlataformaVirtual, $this> */
    public function plataformaVirtual(): BelongsTo
    {
        return $this->belongsTo(PlataformaVirtual::class);
    }

    public function etag(): string
    {
        return '"sesion-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** Sin descifrar: solo indica si hay un enlace guardado. */
    public function tieneEnlace(): bool
    {
        return $this->getRawOriginal('enlace_virtual') !== null;
    }
}
