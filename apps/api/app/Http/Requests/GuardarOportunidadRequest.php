<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una oportunidad. La revisión (`_revision`) la traslada `PrecondicionesPanel`. En el alta se indica el
 * `cliente` (UUID) y, si se quiere, el `servicio` y la `cotizacion` (UUID); después el cliente no cambia. `etapa`,
 * `motivo_perdida`, `responsable_id`, `revision` e `identificador` no se aceptan: los fijan las operaciones del servicio.
 */
class GuardarOportunidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pipeline.gestionar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['titulo', 'monto_estimado', 'probabilidad', 'fecha_cierre_estimada', 'cliente', 'servicio', 'cotizacion'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        foreach (['cliente', 'servicio', 'cotizacion'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = strtolower($datos[$campo]);
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('POST');

        return [
            'titulo' => ['required', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'monto_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'probabilidad' => ['nullable', 'integer', 'between:0,100'],
            'fecha_cierre_estimada' => ['nullable', 'date_format:Y-m-d'],
            'cliente' => $alta ? ['required', 'uuid'] : ['missing'],
            'servicio' => $alta ? ['nullable', 'uuid'] : ['missing'],
            'cotizacion' => $alta ? ['nullable', 'uuid'] : ['missing'],
            'etapa' => ['missing'],
            'motivo_perdida' => ['missing'],
            'responsable_id' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
        ];
    }
}
