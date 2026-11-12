<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutorizarServicioPublicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('servicios.publicar') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['confirmacion_publica' => ['required', 'accepted']];
    }
}
