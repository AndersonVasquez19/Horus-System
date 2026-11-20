<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'enlace_grupo_whatsapp',
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
    /**
     * El enlace de invitación al grupo de WhatsApp es un dato privado: solo lo ve quien lo usa o lo mantiene (matricular o editar el curso).
     */
    public static function enlaceGrupoVisible(?string $enlace): ?string
    {
        $actor = request()->user();

        return $enlace !== null && $actor !== null && ($actor->can('matriculas.crear') || $actor->can('cursos.editar')) ? $enlace : null;
    }

    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /** @return HasOne<CursoPublicacion, $this> */
    public function publicacion(): HasOne
    {
        return $this->hasOne(CursoPublicacion::class);
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
