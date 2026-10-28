<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Services\VerificacionRegistroService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if (VerificacionRegistroService::enabled() && $user->isDirty('email')) {
                $user->email_verified_at = null;
                $user->jwt_version = (int) $user->jwt_version + 1;
                $user->setRememberToken(Str::random(60));
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'jwt_version' => 'integer',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        if (VerificacionRegistroService::enabled()) {
            app(VerificacionRegistroService::class)->solicitar($this->email);
        }
    }
}
