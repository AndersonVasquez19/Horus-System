<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CajaDiaria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cierre de caja de una fecha. Inmutable: una vez guardado no se actualiza ni se borra (la corrección de un error se hace con un
 * movimiento posterior, nunca reescribiendo el cierre). {@see CajaDiaria}
 *
 * @property int $id
 * @property string $identificador
 * @property string $fecha
 * @property int $cantidad_pagos
 * @property int $cantidad_reversos
 * @property string $total_cobrado
 * @property string $total_reversado
 * @property string $total_esperado
 * @property string $total_declarado
 * @property string $diferencia
 * @property array<string, mixed> $detalle
 * @property string|null $observacion
 * @property int|null $cerrado_por
 * @property Carbon|null $cerrado_en
 * @property-read User|null $cerradoPor
 */
class CierreCaja extends Model
{
    protected $table = 'cierres_caja';

    /** @var list<string> */
    protected $fillable = [
        'identificador', 'fecha', 'cantidad_pagos', 'cantidad_reversos', 'total_cobrado', 'total_reversado', 'total_esperado',
        'total_declarado', 'diferencia', 'detalle', 'observacion', 'cerrado_por', 'cerrado_en',
    ];

    protected $hidden = ['id', 'cerrado_por'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Un cierre de caja no se modifica.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Un cierre de caja no se elimina.');
        });
    }

    protected function casts(): array
    {
        return ['detalle' => 'array', 'cerrado_en' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }
}
