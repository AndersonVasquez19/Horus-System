<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevisarSolicitudMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('solicitudes-matricula.ver') === true
            && $this->user()->can('solicitudes-matricula.gestionar');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(['en_revision', 'atendida', 'rechazada'])],
            'nota_revision' => ['nullable', Rule::requiredIf($this->input('estado') === 'rechazada'), 'string', 'min:3', 'max:1000'],
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ];
    }
}
