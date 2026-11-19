<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aula de una sede, con capacidad. La sede se fija al crearla y no cambia. Código, sede, estado e identificador los fija el
 * servicio.
 */
class Aula extends Model
{
    protected $table = 'aulas';

    protected $fillable = ['nombre', 'capacidad'];

    protected $hidden = ['id', 'sede_id'];

    protected function casts(): array
    {
        return ['capacidad' => 'integer'];
    }

    /** @return BelongsTo<Sede, $this> */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
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
