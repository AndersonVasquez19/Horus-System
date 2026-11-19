<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mensaje enviado desde «Contáctanos». Contiene datos personales: nunca se registran sus textos en auditoría ni en
 * notificaciones. El identificador público es el UUID; el id interno no sale de la API.
 *
 * @property int $id
 * @property string $identificador
 * @property string $nombre
 * @property string|null $telefono
 * @property string|null $correo
 * @property string $asunto
 * @property string $mensaje
 * @property string $estado
 * @property int|null $atendido_por
 * @property Carbon|null $atendido_at
 */
class MensajeContacto extends Model
{
    public const ESTADO_NUEVO = 'nuevo';

    public const ESTADO_ATENDIDO = 'atendido';

    /** Asuntos que ofrece el formulario. */
    public const ASUNTOS = ['Tecnologías', 'Educación', 'Asesoramiento', 'Capacitaciones', 'Cursos', 'Otro'];

    protected $table = 'mensajes_contacto';

    protected $fillable = ['nombre', 'telefono', 'correo', 'asunto', 'mensaje', 'visitante'];

    protected $hidden = ['id', 'visitante', 'atendido_por'];

    protected function casts(): array
    {
        return ['atendido_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $mensaje): void {
            $mensaje->identificador ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por');
    }
}
