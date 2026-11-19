<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Bandeja externa unificada (correo Resend / WhatsApp Cloud) para recordatorios y alertas programadas (Ola 3).
 *
 * @property int $id
 * @property string $canal
 * @property string $tipo
 * @property string $clave_unica
 * @property string $delivery_key
 * @property string $status
 * @property int $attempts
 * @property bool $uncertain
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $first_attempt_at
 * @property Carbon|null $available_at
 * @property Carbon|null $dispatched_until
 * @property string|null $lease
 * @property string|null $provider_id
 * @property string|null $provider_status
 * @property Carbon|null $delivered_at
 * @property Carbon|null $bounced_at
 * @property string|null $last_error
 */
class AvisoExterno extends Model
{
    protected $table = 'avisos_externos';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'first_attempt_at' => 'datetime',
            'available_at' => 'datetime',
            'dispatched_until' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
            'attempts' => 'integer',
            'uncertain' => 'boolean',
        ];
    }
}
