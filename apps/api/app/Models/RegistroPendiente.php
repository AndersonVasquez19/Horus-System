<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $consumed_at
 */
class RegistroPendiente extends Model
{
    protected $table = 'registros_pendientes';

    protected $fillable = ['email_hash', 'email'];

    protected $visible = [];

    protected $hidden = ['id', 'email_hash', 'email', 'token_hash', 'expires_at', 'requested_at', 'consumed_at', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
