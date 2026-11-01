<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property int|null $user_id
 * @property string $audiencia
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
