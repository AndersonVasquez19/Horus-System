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

    /** @return BelongsTo<User, $this> */
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

        return self::redactarCambios(is_array($changes) ? $changes : []);
    }

    /**
     * Presentación segura de los cambios: enmascara secretos (por nombre de clave) y el texto libre de
     * búsqueda que las exportaciones de matrículas guardaban antes del Sprint 21.2 (Fase 4, D6-B).
     *
     * Solo transforma la salida: el registro almacenado no se modifica. Toda ruta que muestre o exporte
     * `audit_logs.changes` debe pasar por aquí (pantalla, JSON, ZIP administrativo y serialización del modelo).
     *
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>
     */
    public static function redactarCambios(array $changes): array
    {
        return self::enmascararBusqueda(SensitiveData::redact($changes));
    }

    /**
     * El DNI o el nombre buscado podía quedar en cualquier nivel (`filters.busqueda`, `busqueda`): se
     * enmascara por nombre de clave, como los secretos. Un valor vacío no contiene ningún dato.
     *
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    private static function enmascararBusqueda(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if ($clave === 'busqueda') {
                if ($valor !== null && $valor !== '' && $valor !== []) {
                    $datos[$clave] = SensitiveData::MASK;
                }

                continue;
            }

            if (is_array($valor)) {
                $datos[$clave] = self::enmascararBusqueda($valor);
            }
        }

        return $datos;
    }

    /**
     * Cualquier serialización del modelo (toArray, toJson, respuestas JSON futuras) sale enmascarada.
     * El atributo `changes` leído directamente conserva el valor almacenado.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $atributos = parent::attributesToArray();

        if (isset($atributos['changes']) && is_array($atributos['changes'])) {
            $atributos['changes'] = self::redactarCambios($atributos['changes']);
        }

        return $atributos;
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
