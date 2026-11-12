<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'servicios.crear' : 'servicios.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && is_string($this->input('codigo'))) {
            $this->merge(['codigo' => strtoupper(trim($this->input('codigo')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ['estado' => ['required', Rule::in(['activo', 'inactivo', 'archivado'])]];
        }

        return [
            'codigo' => $this->isMethod('POST')
                ? ['required', 'string', 'max:40', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/D', Rule::unique('servicios_tecnologicos', 'codigo')]
                : ['missing'],
            'nombre' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'estado' => $this->isMethod('POST') ? ['sometimes', Rule::in(['activo', 'inactivo', 'archivado'])] : ['missing'],
            'identificador_publico' => ['missing'],
        ];
    }
}
