<?php

namespace App\Models;

use App\Notifications\RestablecerContrasenaWebNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string|null $telefono
 * @property array<string, mixed>|null $notificacion_preferencias
 * @property Carbon|null $bloqueado_hasta
 * @property string|null $motivo_bloqueo
 * @property bool $debe_cambiar_password
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property Carbon|null $deleted_at
 */
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
     * Preferencias predeterminadas de notificación en perfil.
     *
     * @var array<string, bool>
     */
    public const PREFERENCIAS_NOTIFICACION_DEFECTO = [
        'interno' => true,
        'email' => false,
        'whatsapp' => false,
        'whatsapp_consentimiento' => false,
        'recordatorio_cuotas' => true,
        'recordatorio_sesiones' => true,
        'recordatorio_reclamaciones' => true,
        'alerta_accesos' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
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
            'notificacion_preferencias' => 'array',
            'bloqueado_hasta' => 'datetime',
            'debe_cambiar_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function estaDadoDeBaja(): bool
    {
        return $this->deleted_at !== null;
    }

    public function estaSuspendido(): bool
    {
        return $this->bloqueado_hasta !== null && $this->bloqueado_hasta->isFuture();
    }

    public function tiene2faActivo(): bool
    {
        return $this->two_factor_confirmed_at !== null && is_string($this->two_factor_secret) && $this->two_factor_secret !== '';
    }

    /** @return array<string, bool> */
    public function preferenciasNotificacion(): array
    {
        $guardadas = is_array($this->notificacion_preferencias) ? $this->notificacion_preferencias : [];
        $resultado = [];
        foreach (self::PREFERENCIAS_NOTIFICACION_DEFECTO as $clave => $defecto) {
            $resultado[$clave] = array_key_exists($clave, $guardadas) ? (bool) $guardadas[$clave] : $defecto;
        }

        return $resultado;
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
