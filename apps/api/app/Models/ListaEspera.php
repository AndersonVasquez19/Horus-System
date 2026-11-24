<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persona esperando un cupo en una edición llena (Fase 3.7). Estados: `esperando`, `matriculado`, `cancelado`.
 *
 * @property int $id
 * @property string $identificador
 * @property int $convocatoria_id
 * @property int $estudiante_id
 * @property string $estado
 * @property int|null $activa
 * @property string|null $nota
 * @property Carbon|null $cerrada_at
 * @property Carbon|null $created_at
 * @property-read Estudiante|null $estudiante
 */
class ListaEspera extends Model
{
    protected $table = 'lista_espera';

    public const ESPERANDO = 'esperando';

    public const MATRICULADO = 'matriculado';

    public const CANCELADO = 'cancelado';

    /** @var list<string> */
    protected $fillable = ['identificador', 'convocatoria_id', 'estudiante_id', 'nota', 'registrado_por'];

    protected $hidden = ['id', 'convocatoria_id', 'estudiante_id', 'registrado_por'];

    protected function casts(): array
    {
        return ['cerrada_at' => 'datetime'];
    }

    /** @return BelongsTo<Estudiante, $this> */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}
