<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ImagenesServicios;
use Illuminate\Foundation\Http\FormRequest;

class SubirImagenPrincipalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('servicios.editar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'imagen' => ImagenesServicios::reglas(true),
            'imagen_principal_alt' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'imagen_principal_ruta' => ['missing'],
        ];
    }
}
