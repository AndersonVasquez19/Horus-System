<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PlataformaVirtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de una plataforma virtual externa. Solo nombre y tipo: no se aceptan credenciales, claves ni enlaces.
 */
class GuardarPlataformaVirtualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestructura.gestionar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $datos = [];
        if ($this->has('nombre') && is_string($this->input('nombre'))) {
            $datos['nombre'] = trim($this->input('nombre'));
        }
        if ($this->has('tipo') && is_string($this->input('tipo'))) {
            $datos['tipo'] = strtolower(trim($this->input('tipo')));
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'tipo' => ['required', 'string', Rule::in(PlataformaVirtual::TIPOS)],
            'estado' => ['missing'],
            'identificador' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la plataforma es obligatorio.',
            'tipo.required' => 'Selecciona el tipo de plataforma.',
            'tipo.in' => 'El tipo debe ser Meet, Zoom, Teams u otra.',
            'not_regex' => 'El texto no puede contener los caracteres < ni >.',
        ];
    }
}
