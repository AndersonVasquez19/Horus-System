<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CobranzaAsistida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Contacto de cobro (llamada, WhatsApp manual, correo, visita) y, si hubo, la promesa de pago. Solo se agrega: no se actualiza ni se
 * borra. La nota es texto libre y no se copia a la auditoría. {@see CobranzaAsistida}
 *
 * @property int $id
 * @property string $identificador
 * @property int $obligacion_pago_id
 * @property string $canal
 * @property string $resultado
 * @property string|null $nota
 * @property string|null $promesa_fecha
 * @property string|null $promesa_monto
 * @property int|null $registrado_por
 * @property Carbon|null $created_at
 * @property-read User|null $autor
 */
class SeguimientoCobranza extends Model
{
    public const CANALES = ['llamada', 'whatsapp', 'correo', 'presencial', 'otro'];

    public const RESULTADOS = ['sin_respuesta', 'contactado', 'promesa_pago', 'rechaza'];

    protected $table = 'seguimientos_cobranza';

    /** @var list<string> */
    protected $fillable = ['identificador', 'obligacion_pago_id', 'canal', 'resultado', 'nota', 'promesa_fecha', 'promesa_monto', 'registrado_por'];

    protected $hidden = ['id', 'obligacion_pago_id', 'registrado_por'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Un seguimiento de cobranza no se modifica.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Un seguimiento de cobranza no se elimina.');
        });
    }

    /** @return BelongsTo<ObligacionPago, $this> */
    public function obligacion(): BelongsTo
    {
        return $this->belongsTo(ObligacionPago::class, 'obligacion_pago_id');
    }

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
