<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de una sesión de clase. El enlace virtual solo se escribe (https, hasta 500 caracteres) y nunca se devuelve. La edición, el
 * estado, las revisiones y el identificador no se aceptan aquí. En `PUT` solo cambia lo enviado.
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
        foreach (['tema', 'docente', 'aula', 'plataforma_virtual', 'enlace_virtual'] as $campo) {
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
            'docente' => ['nullable', 'string', 'uuid'],
            'aula' => ['nullable', 'string', 'uuid'],
            'plataforma_virtual' => ['nullable', 'string', 'uuid'],
            'enlace_virtual' => ['nullable', 'string', 'max:500', 'url:https'],
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
            'docente.uuid' => 'El docente seleccionado no es válido.',
            'aula.uuid' => 'El aula seleccionada no es válida.',
            'plataforma_virtual.uuid' => 'La plataforma seleccionada no es válida.',
            'enlace_virtual.url' => 'El enlace debe ser una dirección https válida.',
            'enlace_virtual.max' => 'El enlace admite hasta 500 caracteres.',
        ];
    }
}
