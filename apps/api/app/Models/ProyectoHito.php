<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $fecha_objetivo
 * @property Carbon|null $completado_at
 *
 * Hito de un proyecto. No tiene revisión propia: cada cambio avanza la del proyecto, que es lo que protege la edición.
 */
class ProyectoHito extends Model
{
    protected $table = 'proyecto_hitos';

    protected $fillable = ['nombre', 'fecha_objetivo'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['fecha_objetivo' => 'date', 'completado_at' => 'datetime', 'orden' => 'integer'];
    }

    /** @return BelongsTo<Proyecto, $this> */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }
}
