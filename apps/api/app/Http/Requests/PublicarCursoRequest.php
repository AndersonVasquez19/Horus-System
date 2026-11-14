<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class PublicarCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cursos.publicar') ?? false;
    }

    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        // `after_or_equal:vigente_desde` no tolera que ese campo llegue como array (TypeError y 500): solo se compara
        // contra un texto; si no lo es, ya lo rechaza la regla propia de `vigente_desde` con un 422.
        $comparaConDesde = is_string($this->input('vigente_desde')) ? ['after_or_equal:vigente_desde'] : [];

        return [
            'titulo' => ['required', 'string', 'max:120'],
            'resumen' => ['required', 'string', 'max:500'],
            'modalidad' => ['required', Rule::in(['presencial', 'semipresencial', 'virtual', 'hibrido'])],
            'horario' => ['nullable', 'string', 'max:200'],
            'inscripcion' => ['nullable', 'string', 'max:400'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', ...$comparaConDesde, 'after_or_equal:'.now('America/Lima')->toDateString()],
            'confirmacion_publica' => ['accepted'],
        ];
    }
}
