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
        // `after_or_equal:vigente_desde` no tolera que ese campo llegue como array (TypeError y 500): solo se compara
        // contra un texto; si no lo es, ya lo rechaza la regla propia de `vigente_desde` con un 422.
        $comparaConDesde = is_string($this->input('vigente_desde')) ? ['after_or_equal:vigente_desde'] : [];

        return [
            'titulo' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'resumen' => ['required', 'string', 'max:500', 'not_regex:/[<>]/u'],
            'descripcion' => ['required', 'string', 'max:5000', 'not_regex:/[<>]/u'],
            'categoria' => ['nullable', 'string', 'max:80', 'not_regex:/[<>]/u'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', ...$comparaConDesde],
            'titulo_tarjetas' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'imagen_principal_alt' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
        ];
    }
}
