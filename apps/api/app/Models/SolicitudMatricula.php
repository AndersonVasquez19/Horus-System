<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudMatricula extends Model
{
    protected $table = 'solicitudes_matricula';

    protected $fillable = ['identificador', 'huella', 'curso_titulo', 'modalidad', 'nombres', 'apellidos', 'email', 'telefono', 'mensaje', 'consentimiento_at'];

    protected $hidden = ['huella', 'nombres', 'apellidos', 'email', 'telefono', 'mensaje', 'nota_revision'];

    protected $casts = [
        'nombres' => 'encrypted', 'apellidos' => 'encrypted', 'email' => 'encrypted', 'telefono' => 'encrypted',
        'mensaje' => 'encrypted', 'nota_revision' => 'encrypted', 'consentimiento_at' => 'datetime',
        'revisado_at' => 'datetime', 'revision' => 'integer',
    ];

    public const ESTADOS = ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'atendida' => 'Atendida', 'rechazada' => 'Rechazada'];
}
