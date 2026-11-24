<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Estudiante360;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Nota interna de seguimiento de un estudiante. Solo se agrega: no se actualiza ni se borra. El texto es libre y no se copia a la
 * auditoría. {@see Estudiante360}
 *
 * @property int $id
 * @property string $identificador
 * @property int $estudiante_id
 * @property string $texto
 * @property int|null $registrado_por
 * @property Carbon|null $created_at
 * @property-read User|null $autor
 */
class NotaEstudiante extends Model
{
    protected $table = 'notas_estudiante';

    /** @var list<string> */
    protected $fillable = ['identificador', 'estudiante_id', 'texto', 'registrado_por'];

    protected $hidden = ['id', 'estudiante_id', 'registrado_por'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Una nota de seguimiento no se modifica.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Una nota de seguimiento no se elimina.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
