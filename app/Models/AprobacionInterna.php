<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AprobacionInterna extends Model
{
    protected $table = 'aprobaciones_internas';

    protected $fillable = [
        'titulo',
        'tipo',
        'descripcion',
        'estado',
        'solicitante_id',
        'aprobador_id',
        'comentario',
        'referencia',
    ];

    protected static function booted(): void
    {
        static::created(function (self $aprobacion): void {
            if ($aprobacion->estado === 'pendiente' && $aprobacion->aprobador_id !== null) {
                NotificacionInterna::create([
                    'user_id' => $aprobacion->aprobador_id,
                    'titulo' => 'Nueva solicitud de aprobación',
                    'mensaje' => 'Hay una solicitud pendiente para revisar: '.$aprobacion->titulo,
                    'tipo' => 'warning',
                    'leida' => false,
                    'vinculo' => '/admin/aprobaciones',
                ]);
            }
        });

        static::updated(function (self $aprobacion): void {
            if (! $aprobacion->wasChanged('estado')) {
                return;
            }

            $destinatario = $aprobacion->solicitante_id ?? $aprobacion->aprobador_id;
            if ($destinatario === null) {
                return;
            }

            $mensaje = match ($aprobacion->estado) {
                'aprobado' => 'fue aprobada correctamente.',
                'rechazado' => 'fue rechazada y requiere revisión.',
                default => 'cambió de estado.',
            };

            NotificacionInterna::create([
                'user_id' => $destinatario,
                'titulo' => 'Cambio de aprobación',
                'mensaje' => 'La solicitud "'.$aprobacion->titulo.'" '.$mensaje,
                'tipo' => $aprobacion->estado === 'aprobado' ? 'success' : 'danger',
                'leida' => false,
                'vinculo' => '/admin/aprobaciones',
            ]);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /** @return BelongsTo<User, $this> */
    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobador_id');
    }
}
