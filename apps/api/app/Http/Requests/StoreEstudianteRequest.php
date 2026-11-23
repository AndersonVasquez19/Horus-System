<?php

namespace App\Http\Requests;

use App\Support\Email;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class StoreEstudianteRequest extends FormRequest
{
    public const FECHA_NACIMIENTO_MINIMA = '1920-01-01';

    public const FECHA_NACIMIENTO_MAXIMA = '2010-12-31';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            Email::normalizeRequest($this);
        }
        $datos = [];
        foreach (['dni', 'nombres', 'apellidos', 'direccion'] as $campo) {
            if ($this->has($campo) && is_string($this->input($campo))) {
                $limpio = trim($this->input($campo));
                $datos[$campo] = $limpio === '' && $campo === 'direccion' ? null : $limpio;
            }
        }
        if ($this->has('telefono') && is_string($this->input('telefono'))) {
            $tel = preg_replace('/[\s\-]+/', '', trim($this->input('telefono'))) ?? '';
            $datos['telefono'] = $tel === '' ? null : $tel;
        }
        if ($this->has('fecha_nacimiento') && is_string($this->input('fecha_nacimiento'))) {
            $fecha = trim($this->input('fecha_nacimiento'));
            $datos['fecha_nacimiento'] = $fecha === '' ? null : $fecha;
        }
        if ($datos !== []) {
            $this->merge($datos);
        }
    }

    /** @return array<string, list<string|Unique|In>> */
    public function rules(): array
    {
        return [
            'dni' => ['required', 'digits:8', 'not_regex:/\A0{8}\z/', Rule::unique('estudiantes', 'dni')],
            'nombres' => ['required', 'string', 'min:2', 'max:100', 'regex:/\A[\p{L}\s\'\-\.]+\z/u'],
            'apellidos' => ['required', 'string', 'min:2', 'max:100', 'regex:/\A[\p{L}\s\'\-\.]+\z/u'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('estudiantes', 'email')],
            'telefono' => ['nullable', 'string', 'regex:/\A9\d{8}\z/'],
            'fecha_nacimiento' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.self::FECHA_NACIMIENTO_MINIMA,
                'before_or_equal:'.self::FECHA_NACIMIENTO_MAXIMA,
            ],
            'direccion' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/u'],
            'estado' => ['required', Rule::in(['activo', 'inactivo'])],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.digits' => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.not_regex' => 'El DNI ingresado no es válido.',
            'dni.unique' => 'Ya existe un estudiante registrado con este DNI.',
            'nombres.required' => 'El nombre es obligatorio.',
            'nombres.min' => 'El nombre debe tener al menos 2 caracteres.',
            'nombres.max' => 'El nombre no debe superar los 100 caracteres.',
            'nombres.regex' => 'El nombre solo puede contener letras y espacios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'apellidos.min' => 'Los apellidos deben tener al menos 2 caracteres.',
            'apellidos.max' => 'Los apellidos no deben superar los 100 caracteres.',
            'apellidos.regex' => 'Los apellidos solo pueden contener letras y espacios.',
            'email.email' => 'El email no tiene un formato válido.',
            'email.max' => 'El email no debe superar los 150 caracteres.',
            'email.unique' => 'Este email ya está registrado.',
            'telefono.regex' => 'El teléfono debe tener exactamente 9 dígitos e iniciar con 9.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no puede ser anterior a 1920.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento debe ser como máximo hasta el año 2010 (31/12/2010).',
            'direccion.max' => 'La dirección no debe superar los 255 caracteres.',
            'direccion.not_regex' => 'La dirección contiene caracteres no permitidos.',
            'estado.required' => 'Debes seleccionar el estado del estudiante.',
            'estado.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
