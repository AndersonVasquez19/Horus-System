<?php

namespace App\Http\Requests;

use App\Models\Estudiante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class UpdateEstudianteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Unique|In>> */
    public function rules(): array
    {
        $estudiante = $this->route('estudiante');
        $id = $estudiante instanceof Estudiante ? $estudiante->id : null;

        return [
            'dni' => ['required', 'digits:8', Rule::unique('estudiantes', 'dni')->ignore($id)],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('estudiantes', 'email')->ignore($id)],
            'telefono' => ['nullable', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::in(['activo', 'inactivo'])],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.unique' => 'Ya existe un estudiante registrado con este DNI.',
            'nombres.required' => 'El nombre es obligatorio.',
            'nombres.max' => 'El nombre no debe superar los 100 caracteres.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'apellidos.max' => 'Los apellidos no deben superar los 100 caracteres.',
            'email.email' => 'El email no tiene un formato válido.',
            'email.max' => 'El email no debe superar los 150 caracteres.',
            'email.unique' => 'Este email ya está registrado.',
            'telefono.max' => 'El teléfono no debe superar los 20 caracteres.',
            'fecha_nacimiento.date' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'direccion.max' => 'La dirección no debe superar los 255 caracteres.',
            'estado.required' => 'Debes seleccionar el estado del estudiante.',
            'estado.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
