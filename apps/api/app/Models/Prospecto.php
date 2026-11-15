<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $proximo_seguimiento Fecha convertida por el cast `date`.
 *
 * Prospecto del panel de servicios: contacto comercial aún no convertido en cliente. El `id` interno no sale por la
 * API. Los campos asignables en masa son los que edita el panel; estado, motivo, vínculos y revisión los fijan los
 * servicios.
 */
class Prospecto extends Model
{
    use SoftDeletes;

    /** Orígenes que se pueden elegir a mano; `cotizacion` lo fija el sistema al convertir una solicitud. */
    public const ORIGENES_MANUALES = ['web', 'referido', 'evento', 'llamada', 'otro'];

    public const ORIGENES = ['web', 'cotizacion', 'referido', 'evento', 'llamada', 'otro'];

    public const ESTADOS = ['nuevo', 'contactado', 'calificado', 'descartado', 'convertido'];

    /**
     * Transiciones que se piden con `estado`. `convertido` solo se alcanza con la conversión (crea o vincula el cliente).
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'nuevo' => ['contactado', 'descartado'],
        'contactado' => ['calificado', 'descartado'],
        'calificado' => ['descartado'],
        'descartado' => ['nuevo'],
        'convertido' => [],
    ];

    protected $fillable = ['nombre_contacto', 'empresa', 'email', 'telefono', 'origen', 'proximo_seguimiento'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'proximo_seguimiento' => 'date'];
    }

    public function etag(): string
    {
        return '"prospecto-'.$this->identificador.'-v'.$this->revision.'"';
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return BelongsTo<ServicioTecnologico, $this> */
    public function servicioInteres(): BelongsTo
    {
        return $this->belongsTo(ServicioTecnologico::class, 'servicio_interes_id');
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<SolicitudCotizacion, $this> */
    public function solicitudCotizacion(): BelongsTo
    {
        return $this->belongsTo(SolicitudCotizacion::class, 'solicitud_cotizacion_id');
    }
}
