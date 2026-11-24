<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $available_at
 * @property Carbon|null $first_attempt_at
 */
class MatriculaNotification extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'matricula_id', 'delivery_key', 'status', 'attempts', 'uncertain', 'payload', 'first_attempt_at', 'available_at',
        'dispatched_until', 'lease', 'provider_id', 'provider_status', 'delivered_at', 'bounced_at', 'last_error',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'available_at' => 'datetime',
            'first_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'bounced_at' => 'datetime',
            'attempts' => 'integer',
            'uncertain' => 'boolean',
        ];
    }
}
