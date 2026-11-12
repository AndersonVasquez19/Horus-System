<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Solo se validan (y por tanto solo se persisten) las claves permitidas; cualquier otra,
     * incluida la antigua `api_resend`, se ignora.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'settings.nombre_empresa' => ['nullable', 'string', 'max:255'],
            'settings.telefono' => ['nullable', 'string', 'max:255'],
            'settings.correo_contacto' => ['nullable', 'email', 'max:255'],
            'settings.direccion' => ['nullable', 'string', 'max:255'],
            'settings.moneda' => ['nullable', 'string', 'max:20'],
        ];
    }
}
