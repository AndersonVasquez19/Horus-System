<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Convocatoria;
use App\Support\Entrada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de una convocatoria. La revisión (`_revision`) la traslada `PrecondicionesPanel`. En el alta se indica el curso
 * (`curso_id`); después no cambia. `estado`, `codigo`, `cupos_disponibles`, `revision` e `identificador` no se aceptan: los
 * fijan las operaciones del servicio.
 */
class GuardarConvocatoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'convocatorias.crear' : 'convocatorias.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos', 'curso_id'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        if (isset($datos['modalidad']) && is_string($datos['modalidad'])) {
            $datos['modalidad'] = strtolower($datos['modalidad']);
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'modalidad' => ['required', 'string', Rule::in(Convocatoria::MODALIDADES)],
            'fecha_inicio' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2035-12-31'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio', 'before_or_equal:2035-12-31'],
            'cupos_maximos' => ['required', 'integer', 'min:1', 'max:'.Entrada::entero(config('academico.cupos_maximos', 10))],
            'curso_id' => $this->isMethod('POST') ? ['required', 'integer', 'min:1'] : ['missing'],
            'estado' => ['missing'],
            'codigo' => ['missing'],
            'cupos_disponibles' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['cupos_maximos.max' => 'Una edición admite como máximo '.Entrada::entero(config('academico.cupos_maximos', 10)).' cupos.'];
    }
}
