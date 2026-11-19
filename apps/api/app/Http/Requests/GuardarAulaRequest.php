<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de un aula. La sede y el código solo se indican al crearla (no cambian después); estado e identificador los fija el
 * servicio.
 */
class GuardarAulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestructura.gestionar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['sede', 'codigo', 'nombre', 'capacidad'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('POST');

        return [
            'sede' => $alta ? ['required', 'string', 'uuid'] : ['missing'],
            'codigo' => $alta ? ['required', 'string', 'regex:/\A[A-Za-z0-9-]{1,20}\z/D'] : ['missing'],
            'nombre' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:5000'],
            'estado' => ['missing'],
            'identificador' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sede.required' => 'Selecciona la sede del aula.',
            'sede.uuid' => 'La sede seleccionada no es válida.',
            'codigo.required' => 'El código del aula es obligatorio.',
            'codigo.regex' => 'El código admite letras, números y guiones (hasta 20 caracteres).',
            'nombre.required' => 'El nombre del aula es obligatorio.',
            'capacidad.required' => 'La capacidad del aula es obligatoria.',
            'capacidad.integer' => 'La capacidad debe ser un número entero.',
            'capacidad.min' => 'La capacidad debe ser al menos 1.',
            'capacidad.max' => 'La capacidad no puede superar 5000.',
            'not_regex' => 'El texto no puede contener los caracteres < ni >.',
        ];
    }
}
