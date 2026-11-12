<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estudiante extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dni',
        'nombres',
        'apellidos',
        'email',
        'telefono',
        'fecha_nacimiento',
        'direccion',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /** @return BelongsToMany<Curso, $this> */
    public function cursos(): BelongsToMany
    {
        return $this->belongsToMany(Curso::class, 'matriculas')
            ->withPivot(['id', 'fecha_matricula', 'estado', 'monto_pagado', 'observaciones', 'deleted_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at')
            ->wherePivot('estado', '!=', 'anulada');
    }
}
