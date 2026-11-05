<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrepararServicioPublicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('servicios.editar') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'resumen' => ['required', 'string', 'max:500', 'not_regex:/[<>]/u'],
            'descripcion' => ['required', 'string', 'max:5000', 'not_regex:/[<>]/u'],
            'categoria' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
            'titulo_tarjetas' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'imagen_principal_alt' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
        ];
    }
}
