<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Curso extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'categoria',
        'duracion_horas',
        'precio',
        'modalidad',
        'horario',
        'cupos_maximos',
        'cupos_disponibles',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'duracion_horas' => 'integer',
            'precio' => 'decimal:2',
            'cupos_maximos' => 'integer',
            'cupos_disponibles' => 'integer',
        ];
    }

    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /** @return BelongsToMany<Estudiante, $this> */
    public function estudiantes(): BelongsToMany
    {
        return $this->belongsToMany(Estudiante::class, 'matriculas')
            ->withPivot(['id', 'fecha_matricula', 'estado', 'monto_pagado', 'observaciones', 'deleted_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at')
            ->wherePivot('estado', '!=', 'anulada');
    }
}
