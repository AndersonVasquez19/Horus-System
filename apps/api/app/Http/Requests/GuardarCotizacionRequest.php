<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarCotizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->hasRole('admin') && $actor->can($this->isMethod('POST') ? 'cotizaciones.crear' : 'cotizaciones.editar');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presencia = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'contacto_nombre' => [$presencia, 'required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'necesidad_cliente' => [$presencia, 'required', 'string', 'max:5000', 'not_regex:/[<>]/u'],
            'contacto_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'contacto_telefono' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[+0-9() .-]{5,30}\z/D'],
            'servicio_uuid' => ['sometimes', 'nullable', 'uuid'],
            'estado' => ['missing'], 'responsable_id' => ['missing'],
            'identificador' => ['missing'], 'codigo' => ['missing'],
            'servicio_nombre_historico' => ['missing'], 'servicio_id' => ['missing'],
            'creado_por' => ['missing'], 'revision' => ['missing'],
        ];
    }
}
