<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatIntent extends Model
{
    protected $fillable = [
        'nombre',
        'keywords',
        'respuesta',
        'prioridad',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'prioridad' => 'integer',
            'activo' => 'boolean',
        ];
    }
}
