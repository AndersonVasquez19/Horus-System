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
            'fecha' => [$obligatorio, 'date_format:Y-m-d'],
            'hora_inicio' => [$obligatorio, 'date_format:H:i'],
            'hora_fin' => [$obligatorio, 'date_format:H:i'],
            'tema' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha.required' => 'Indica la fecha de la sesión.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'hora_inicio.required' => 'Indica la hora de inicio.',
            'hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'hora_fin.required' => 'Indica la hora de fin.',
            'hora_fin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
            'tema.max' => 'El tema admite hasta 200 caracteres.',
        ];
    }
}
