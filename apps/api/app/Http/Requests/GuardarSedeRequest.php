<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una sede. El código solo se indica al crearla (no cambia después); estado e identificador los fija el servicio.
 */
class GuardarSedeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestructura.gestionar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['codigo', 'nombre', 'direccion', 'ciudad'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => $this->isMethod('POST') ? ['required', 'string', 'regex:/\A[A-Za-z0-9-]{2,20}\z/D'] : ['missing'],
            'nombre' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'direccion' => ['required', 'string', 'max:200', 'not_regex:/[<>]/u'],
            'ciudad' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'estado' => ['missing'],
            'identificador' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El código de la sede es obligatorio.',
            'codigo.regex' => 'El código admite letras, números y guiones (2 a 20 caracteres).',
            'nombre.required' => 'El nombre de la sede es obligatorio.',
            'direccion.required' => 'La dirección de la sede es obligatoria.',
            'not_regex' => 'El texto no puede contener los caracteres < ni >.',
        ];
    }
}
