<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipos tras los casts: la columna keywords es JSON en la migración, pero el modelo la
 * entrega como array (Larastan la tipaba como string al leer solo la migración).
 *
 * @property int $id
 * @property string $nombre
 * @property array<int, string>|null $keywords
 * @property string $respuesta
 * @property int $prioridad
 * @property bool $activo
 */
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
