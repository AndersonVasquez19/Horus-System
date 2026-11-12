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
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'available_at' => 'datetime',
            'first_attempt_at' => 'datetime',
            'attempts' => 'integer',
            'uncertain' => 'boolean',
        ];
    }
}
