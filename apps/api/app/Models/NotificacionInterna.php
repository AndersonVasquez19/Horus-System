<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Notificación interna del panel.
 *
 * La audiencia es explícita e independiente de user_id (migración 2026_10_31_000032):
 *  - personal: dirigida a un usuario; si se elimina, la FK deja user_id en NULL pero sigue siendo personal
 *    y deja de ser visible para nadie.
 *  - general: compartida por el personal del panel; siempre sin destinatario.
 *  - indeterminada: histórico sin destinatario cierto; nunca visible ni contable.
 * user_id NULL no implica general. Toda consulta de bandeja o contador usa visiblePara().
 *
 * Lectura (10/11/2026): las personales usan `leida`; cada usuario registra su lectura de una general en
 * `notificacion_lecturas` (noLeidasPara(), estaLeidaPara(), marcarLeidaPara()).
 *
 * @property int|null $user_id
 * @property string $audiencia
 * @property bool $leida
 * @property string|null $vinculo
 */
class NotificacionInterna extends Model
{
    public const AUDIENCIA_PERSONAL = 'personal';

    public const AUDIENCIA_GENERAL = 'general';

    public const AUDIENCIA_INDETERMINADA = 'indeterminada';

    protected $table = 'notificaciones_internas';

    protected $fillable = [
        'user_id',
        'audiencia',
        'titulo',
        'mensaje',
        'tipo',
        'leida',
        'vinculo',
    ];

    protected $casts = [
        'leida' => 'boolean',
    ];

    /**
     * Crea una notificación personal. El destino no puede sobrescribirse desde $atributos.
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function crearPersonal(int $userId, array $atributos): self
    {
        return static::query()->create(
            ['user_id' => $userId, 'audiencia' => self::AUDIENCIA_PERSONAL] + self::sinDestino($atributos)
        );
    }

    /**
     * Crea una notificación general (sin destinatario). El destino no puede sobrescribirse desde $atributos.
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function crearGeneral(array $atributos): self
    {
        return static::query()->create(
            ['user_id' => null, 'audiencia' => self::AUDIENCIA_GENERAL] + self::sinDestino($atributos)
        );
    }

    /**
     * Único criterio de visibilidad: personales propias y generales explícitas.
     * Excluye personales ajenas o huérfanas e indeterminadas.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisiblePara(Builder $query, User $usuario): void
    {
        $query->where(function (Builder $q) use ($usuario): void {
            $q->where(function (Builder $personal) use ($usuario): void {
                $personal->where('audiencia', self::AUDIENCIA_PERSONAL)->where('user_id', $usuario->getKey());
            })->orWhere(function (Builder $general): void {
                $general->where('audiencia', self::AUDIENCIA_GENERAL)->whereNull('user_id');
            });
        });
    }

    public function esVisiblePara(User $usuario): bool
    {
        return match ($this->audiencia) {
            self::AUDIENCIA_PERSONAL => $this->user_id !== null && (int) $this->user_id === (int) $usuario->getKey(),
            self::AUDIENCIA_GENERAL => $this->user_id === null,
            default => false,
        };
    }

    /**
     * No leídas para ese usuario. Personales: `leida=false` en la fila. Generales: `leida=false` (una general
     * leída antes de la lectura por usuario se sigue considerando leída para todos) y sin lectura propia.
     * Se combina siempre con visiblePara().
     *
     * @param  Builder<self>  $query
     */
    public function scopeNoLeidasPara(Builder $query, User $usuario): void
    {
        $query->where('leida', false)->where(function (Builder $q) use ($usuario): void {
            $q->where('audiencia', '!=', self::AUDIENCIA_GENERAL)
                ->orWhereDoesntHave('lecturas', function (Builder $lectura) use ($usuario): void {
                    $lectura->where('user_id', $usuario->getKey());
                });
        });
    }

    /**
     * Precarga solo la lectura del usuario indicado (para estaLeidaPara() sin consultas por fila).
     *
     * @param  Builder<self>  $query
     */
    public function scopeConLecturaDe(Builder $query, User $usuario): void
    {
        $query->with(['lecturas' => function ($lectura) use ($usuario): void {
            $lectura->where('user_id', $usuario->getKey());
        }]);
    }

    public function estaLeidaPara(User $usuario): bool
    {
        if ($this->leida || $this->audiencia !== self::AUDIENCIA_GENERAL) {
            return $this->leida;
        }

        if ($this->relationLoaded('lecturas')) {
            return $this->lecturas->contains('user_id', $usuario->getKey());
        }

        return $this->lecturas()->where('user_id', $usuario->getKey())->exists();
    }

    /**
     * Marca la notificación como leída para ese usuario. La visibilidad se comprueba antes (esVisiblePara).
     * Personal: cambia `leida` en la fila. General: registra la lectura propia y no toca la de los demás.
     * Repetirlo no tiene efecto.
     */
    public function marcarLeidaPara(User $usuario): void
    {
        if ($this->audiencia !== self::AUDIENCIA_GENERAL) {
            if (! $this->leida) {
                $this->update(['leida' => true]);
            }

            return;
        }

        if ($this->leida) {
            return;
        }

        NotificacionLectura::query()->insertOrIgnore([
            'notificacion_id' => $this->getKey(),
            'user_id' => $usuario->getKey(),
            'leida_at' => now(),
        ]);
        $this->unsetRelation('lecturas');
    }

    /**
     * Vínculo apto para mostrarse: solo rutas internas del sitio (empieza por "/" y no por "//" ni "/\") y
     * nunca el módulo retirado de procesos.
     */
    public function vinculoSeguro(): ?string
    {
        $vinculo = trim((string) $this->vinculo);

        if ($vinculo === '' || ! str_starts_with($vinculo, '/') || str_starts_with($vinculo, '//') || str_starts_with($vinculo, '/\\')) {
            return null;
        }

        $ruta = (string) parse_url($vinculo, PHP_URL_PATH);

        if (Str::is(['/admin/procesos', '/admin/procesos/*'], $ruta)) {
            return null;
        }

        return $vinculo;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<NotificacionLectura, $this> */
    public function lecturas(): HasMany
    {
        return $this->hasMany(NotificacionLectura::class, 'notificacion_id');
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private static function sinDestino(array $atributos): array
    {
        unset($atributos['user_id'], $atributos['audiencia']);

        return $atributos;
    }
}
