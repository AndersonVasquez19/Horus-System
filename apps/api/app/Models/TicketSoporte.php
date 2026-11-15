<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $vence_at Límite del SLA según la prioridad, contado desde la creación.
 * @property Carbon|null $resuelto_at
 * @property Carbon|null $cerrado_at
 *
 * Ticket de soporte técnico. El `id` interno no sale por la API. Los campos asignables en masa son los que edita el
 * panel; código, estado, vínculos, fechas del ciclo y revisión los fijan los servicios.
 */
class TicketSoporte extends Model
{
    use SoftDeletes;

    protected $table = 'tickets_soporte';

    public const ESTADOS = ['abierto', 'en_progreso', 'en_espera_cliente', 'resuelto', 'cerrado'];

    public const PRIORIDADES = ['baja', 'media', 'alta', 'critica'];

    public const CANALES = ['telefono', 'correo', 'presencial', 'web'];

    /** Horas hasta el vencimiento según la prioridad (SLA por defecto del diseño). */
    public const SLA_HORAS = ['critica' => 4, 'alta' => 8, 'media' => 24, 'baja' => 72];

    /** Días tras la resolución en los que todavía se puede reabrir el mismo ticket. */
    public const DIAS_REAPERTURA = 7;

    /**
     * Transiciones que se piden con `estado`. `cerrado` es terminal; `resuelto → en_progreso` solo dentro de
     * {@see self::DIAS_REAPERTURA} días (lo comprueba el servicio).
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'abierto' => ['en_progreso'],
        'en_progreso' => ['en_espera_cliente', 'resuelto'],
        'en_espera_cliente' => ['en_progreso'],
        'resuelto' => ['en_progreso', 'cerrado'],
        'cerrado' => [],
    ];

    protected $fillable = ['asunto', 'descripcion', 'prioridad', 'canal'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'vence_at' => 'datetime', 'resuelto_at' => 'datetime', 'cerrado_at' => 'datetime'];
    }

    public function esAbierto(): bool
    {
        return $this->estado !== 'cerrado';
    }

    /** Vencido: pasó el límite del SLA y nadie lo ha resuelto todavía. */
    public function estaVencido(): bool
    {
        return in_array($this->estado, ['abierto', 'en_progreso', 'en_espera_cliente'], true) && $this->vence_at->isPast();
    }

    public function etag(): string
    {
        return '"ticket-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<Proyecto, $this> */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /** @return BelongsTo<User, $this> */
    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    /** @return HasMany<TicketNota, $this> */
    public function notas(): HasMany
    {
        return $this->hasMany(TicketNota::class, 'ticket_id')->orderByDesc('id');
    }
}
