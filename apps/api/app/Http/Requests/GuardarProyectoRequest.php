<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de un proyecto. La revisión (`_revision`) la traslada `PrecondicionesPanel`. En el alta se indica el `cliente`
 * (UUID) o la `oportunidad` ganada (UUID) de la que nace, y entonces el nombre es opcional; después ninguno cambia.
 * `estado`, `codigo`, `responsable_id`, `fecha_fin_real`, `revision` e `identificador` no se aceptan: los fijan las
 * operaciones del servicio.
 */
class GuardarProyectoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'proyectos.crear' : 'proyectos.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto', 'cliente', 'oportunidad'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        foreach (['cliente', 'oportunidad'] as $campo) {
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
            'nombre' => [$alta ? 'required_without:oportunidad' : 'required', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'descripcion' => ['nullable', 'string', 'max:5000', 'not_regex:/[<>]/u'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin_estimada' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'presupuesto' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'cliente' => $alta ? ['nullable', 'uuid', 'required_without:oportunidad', 'prohibits:oportunidad'] : ['missing'],
            'oportunidad' => $alta ? ['nullable', 'uuid'] : ['missing'],
            'estado' => ['missing'],
            'codigo' => ['missing'],
            'responsable_id' => ['missing'],
            'fecha_fin_real' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
        ];
    }
}
