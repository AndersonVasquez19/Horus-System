<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\GestionPagos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Obligación de pago de una matrícula. Todo lo que no es dato de captura (matrícula, estado, autor, montos del libro) lo fija
 * {@see GestionPagos}. Los importes se manejan como texto decimal o céntimos enteros, nunca como `float`.
 */
class ObligacionPago extends Model
{
    public const PLANES = ['completo', 'fraccionado'];

    protected $table = 'obligaciones_pago';

    protected $guarded = [];

    protected $hidden = ['id', 'matricula_id', 'creado_por'];

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class)->withTrashed();
    }

    /** @return HasMany<CuotaObligacion, $this> */
    public function cuotas(): HasMany
    {
        return $this->hasMany(CuotaObligacion::class)->orderBy('numero');
    }

    /** @return HasMany<TransaccionPago, $this> */
    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class)->orderBy('id');
    }
}
