<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Docente: persona identificada solo por su DNI peruano (8 dígitos, texto, único). El `id` interno no sale por la API; el
 * identificador público es el UUID. DNI, estado, vínculo con una cuenta e identificador no son asignables en masa: los fija el
 * servicio. Eliminación lógica para conservar el historial de ediciones dictadas.
 */
class Docente extends Model
{
    use SoftDeletes;

    public const ESTADOS = ['activo', 'inactivo'];

    public const ROLES = ['principal', 'apoyo'];

    protected $fillable = ['nombres', 'apellidos', 'email', 'telefono', 'especialidad'];

    protected $hidden = ['id', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Convocatoria, $this> */
    public function convocatorias(): BelongsToMany
    {
        return $this->belongsToMany(Convocatoria::class, 'convocatoria_docentes')->withPivot('rol')->withTimestamps();
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }
}
