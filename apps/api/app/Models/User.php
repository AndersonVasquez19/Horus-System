<?php

namespace App\Models;

use App\Notifications\RestablecerContrasenaWebNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if (! $user->isDirty('email')) {
                return;
            }

            // Cambiar el correo siempre revoca los JWT vigentes, con independencia de otras condiciones.
            // Si la misma operación ya subió la versión (p. ej. cambio simultáneo de contraseña), no se suma otra vez.
            if (! $user->isDirty('jwt_version')) {
                $user->jwt_version = (int) $user->jwt_version + 1;
            }
            $user->setRememberToken(Str::random(60));
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

    /**
     * Expediente de estudiante vinculado a esta cuenta (opcional; un usuario, un expediente). No habilita ningún portal.
     *
     * @return HasOne<Estudiante, $this>
     */
    public function estudiante(): HasOne
    {
        return $this->hasOne(Estudiante::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RestablecerContrasenaWebNotification($token));
    }
}
