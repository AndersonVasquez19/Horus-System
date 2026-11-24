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
 *
 * @property string $monto_total Importe como texto decimal.
 */
class ObligacionPago extends Model
{
    public const PLANES = ['completo', 'fraccionado'];

    protected $table = 'obligaciones_pago';

    /**
     * Lista cerrada: `id` y cualquier columna futura nunca se asignan en masa. {@see GestionPagos} asigna los
     * atributos uno a uno; no se debe llenar este modelo desde una petición.
     *
     * @var list<string>
     */
    protected $fillable = [
        'identificador', 'matricula_id', 'concepto', 'plan', 'monto_total', 'moneda', 'estado', 'motivo_anulacion', 'creado_por',
    ];

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
