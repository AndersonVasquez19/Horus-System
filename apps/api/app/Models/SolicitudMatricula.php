<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudMatricula extends Model
{
    protected $table = 'solicitudes_matricula';

    protected $fillable = ['identificador', 'huella', 'curso_titulo', 'modalidad', 'nombres', 'apellidos', 'email', 'telefono', 'mensaje', 'consentimiento_at',
        'es_menor', 'representante_nombre', 'representante_email', 'representante_telefono', 'huella_duplicado'];

    protected $hidden = ['huella', 'huella_duplicado', 'nombres', 'apellidos', 'email', 'telefono', 'mensaje', 'nota_revision',
        'representante_nombre', 'representante_email', 'representante_telefono'];

    protected $casts = [
        'nombres' => 'encrypted', 'apellidos' => 'encrypted', 'email' => 'encrypted', 'telefono' => 'encrypted',
        'mensaje' => 'encrypted', 'nota_revision' => 'encrypted', 'es_menor' => 'boolean',
        'representante_nombre' => 'encrypted', 'representante_email' => 'encrypted', 'representante_telefono' => 'encrypted', 'consentimiento_at' => 'datetime',
        'revisado_at' => 'datetime', 'revision' => 'integer',
    ];

    /** Estados en los que la solicitud sigue abierta (aún no atendida ni rechazada). */
    public const ESTADOS_ABIERTOS = ['pendiente', 'en_revision'];

    /** Código corto para que la persona cite su solicitud; no es secreto ni sustituye al identificador. */
    public static function codigoDe(string $identificador): string
    {
        return 'SM-'.strtoupper(substr($identificador, 0, 8));
    }

    public function codigoSeguimiento(): string
    {
        return self::codigoDe((string) $this->identificador);
    }

    public const ESTADOS = ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'atendida' => 'Atendida', 'rechazada' => 'Rechazada'];
}
