<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCertificado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Certificado académico institucional con código único verificable (`HORUS-AAAA-NNNN`).
 *
 * @property string $identificador
 * @property string $codigo
 * @property int $matricula_id
 * @property int|null $convocatoria_id
 * @property int $estudiante_id
 * @property int $curso_id
 * @property EstadoCertificado $estado
 * @property string|null $porcentaje_asistencia
 * @property string|null $nota_final
 * @property Carbon $emitido_en
 * @property int|null $emitido_por
 * @property string|null $motivo_excepcion
 * @property Carbon|null $anulado_en
 * @property int|null $anulado_por
 * @property string|null $motivo_anulacion
 * @property Carbon|null $correo_enviado_at
 * @property string|null $correo_error
 */
class Certificado extends Model
{
    public const ESTADOS = ['emitido', 'anulado'];

    protected $table = 'certificados';

    protected $fillable = [
        'identificador',
        'codigo',
        'matricula_id',
        'convocatoria_id',
        'estudiante_id',
        'curso_id',
        'estado',
        'porcentaje_asistencia',
        'nota_final',
        'emitido_en',
        'emitido_por',
        'motivo_excepcion',
        'anulado_en',
        'anulado_por',
        'motivo_anulacion',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCertificado::class,
            'porcentaje_asistencia' => 'decimal:2',
            'nota_final' => 'decimal:2',
            'emitido_en' => 'datetime',
            'anulado_en' => 'datetime',
            'correo_enviado_at' => 'datetime',
        ];
    }

    /**
     * Iniciales abreviadas del titular para verificación pública sin exponer PII (ej. "J. P. G.").
     */
    public function titularAbreviado(): string
    {
        $est = $this->estudiante;
        if ($est === null) {
            return '—';
        }
        $texto = trim($est->nombres.' '.$est->apellidos);
        if ($texto === '') {
            return '—';
        }
        $partes = preg_split('/\s+/u', $texto) ?: [];
        $iniciales = [];
        foreach ($partes as $parte) {
            if ($parte !== '') {
                $iniciales[] = mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8').'.';
            }
        }

        return implode(' ', $iniciales);
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class)->withTrashed();
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class)->withTrashed();
    }

    /** @return BelongsTo<Estudiante, $this> */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class)->withTrashed();
    }

    /** @return BelongsTo<Curso, $this> */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitido_por');
    }

    /** @return BelongsTo<User, $this> */
    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
