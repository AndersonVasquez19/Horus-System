<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una sesión de clase (fecha, horas, tema y número). La edición, el estado, las revisiones y el identificador no se
 * aceptan aquí. En `PUT` solo cambia lo enviado.
 */
class GuardarSesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'sesiones.crear' : 'sesiones.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $datos = [];
        foreach (['tema'] as $campo) {
            if ($this->has($campo) && is_string($this->input($campo))) {
                $valor = trim($this->input($campo));
                $datos[$campo] = $valor === '' ? null : $valor;
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obligatorio = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'numero' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'fecha' => [$obligatorio, 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2035-12-31'],
            'hora_inicio' => [$obligatorio, 'date_format:H:i'],
            'hora_fin' => [$obligatorio, 'date_format:H:i', ...(is_string($this->input('hora_inicio')) ? ['after:hora_inicio'] : [])],
            'tema' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha.required' => 'Indica la fecha de la sesión.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'fecha.after_or_equal' => 'La fecha de la sesión debe ser a partir del año 2020.',
            'fecha.before_or_equal' => 'La fecha de la sesión no puede superar el año 2035.',
            'hora_inicio.required' => 'Indica la hora de inicio.',
            'hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'hora_fin.required' => 'Indica la hora de fin.',
            'hora_fin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
            'tema.max' => 'El tema admite hasta 200 caracteres.',
            'tema.not_regex' => 'El tema contiene caracteres no permitidos.',
        ];
    }
}
