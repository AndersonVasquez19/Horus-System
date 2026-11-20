<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CursoPublicacion extends Model
{
    protected $table = 'curso_publicaciones';

    protected $fillable = [
        'curso_id', 'titulo', 'resumen', 'imagen_ruta', 'modalidad', 'horario',
        'fecha_inicio', 'duracion', 'certificacion', 'temario', 'inscripcion',
        'vigente_desde', 'vigente_hasta', 'publicada', 'autorizado_por', 'autorizado_at',
    ];

    protected function casts(): array
    {
        return [
            'publicada' => 'boolean',
            'fecha_inicio' => 'date',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'autorizado_at' => 'datetime',
        ];
    }
}
