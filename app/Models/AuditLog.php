<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'changes',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cambios del registro con los valores sensibles enmascarados.
     *
     * Protege también los registros históricos creados antes de que {@see self::record()}
     * enmascarara los secretos: úsese siempre para mostrar o exportar.
     *
     * @return array<array-key, mixed>
     */
    public function redactedChanges(): array
    {
        // getAttribute: `$this->changes` dentro del modelo sería la propiedad interna $changes de Eloquent.
        $changes = $this->getAttribute('changes');

        return SensitiveData::redact(is_array($changes) ? $changes : []);
    }

    /**
     * Registra un evento de auditoría. Los valores de claves sensibles (contraseñas, tokens,
     * claves de API…) nunca se persisten: se guardan enmascarados.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function record(string $action, ?string $modelType = null, mixed $modelId = null, array $changes = [], ?int $userId = null): self
    {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'changes' => SensitiveData::redact($changes),
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
