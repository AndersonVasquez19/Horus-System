<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AprobacionInterna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class StoreAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', Rule::in(AprobacionInterna::TIPOS_PERMITIDOS)],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            // Se validan por compatibilidad con clientes previos, pero se ignoran: el solicitante es la sesión
            // y el aprobador solo se fija al decidir.
            'solicitante_id' => ['nullable', 'integer', 'exists:users,id'],
            'aprobador_id' => ['nullable', 'integer', 'exists:users,id'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'in:pendiente,aprobado,rechazado'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.in' => 'Selecciona un tipo de solicitud válido (compra u operativo).',
        ];
    }
}
