<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GestionarCotizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->hasRole('admin') && $actor->can('cotizaciones.gestionar');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'estado' => ['estado' => ['required', Rule::in(['recibida', 'en_revision'])]],
            'responsable' => ['responsable_id' => ['present', 'nullable', 'integer', 'min:1', 'max:2147483647']],
            'seguimiento' => ['nota' => ['required', 'string', 'max:3000', 'not_regex:/[<>]/u'],
                'proximo_seguimiento' => ['sometimes', 'nullable', 'date_format:Y-m-d']],
            default => [],
        };
    }
}
