<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Docente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de un docente. El DNI (solo peruano, 8 dígitos, texto) se indica únicamente al crear y no cambia después; identificador,
 * vínculo con cuenta y eliminación no se aceptan. `estado` solo al editar.
 */
class GuardarDocenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'docentes.crear' : 'docentes.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['dni', 'nombres', 'apellidos', 'email', 'telefono', 'especialidad', 'estado'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        if (isset($datos['email']) && is_string($datos['email'])) {
            $datos['email'] = mb_strtolower($datos['email']);
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('POST');

        return [
            'dni' => $alta ? ['required', 'string', 'regex:/\A[0-9]{8}\z/D'] : ['missing'],
            'nombres' => ['required', 'string', 'max:100', 'not_regex:/[<>]/u'],
            'apellidos' => ['required', 'string', 'max:100', 'not_regex:/[<>]/u'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'regex:/\A[0-9+()\s-]{6,20}\z/D'],
            'especialidad' => ['nullable', 'string', 'max:150', 'not_regex:/[<>]/u'],
            'estado' => $alta ? ['missing'] : ['sometimes', 'string', Rule::in(Docente::ESTADOS)],
            'identificador' => ['missing'],
            'user_id' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.string' => 'El DNI debe ser texto de 8 dígitos.',
            'dni.regex' => 'El DNI debe tener exactamente 8 dígitos.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'telefono.regex' => 'El teléfono admite dígitos, +, paréntesis, espacios y guiones (6 a 20 caracteres).',
            'estado.in' => 'El estado debe ser activo o inactivo.',
            'not_regex' => 'El texto no puede contener los caracteres < ni >.',
        ];
    }
}
