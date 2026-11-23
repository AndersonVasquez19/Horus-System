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
            'titulo' => ['required', 'string', 'min:3', 'max:120', 'not_regex:/[<>]/u'],
            'resumen' => ['required', 'string', 'min:10', 'max:500'],
            'modalidad' => ['required', Rule::in(['presencial', 'semipresencial', 'virtual', 'hibrido'])],
            'horario' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2035-12-31'],
            'duracion' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'certificacion' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
            'temario' => ['nullable', 'string', 'max:1500', 'not_regex:/[<>]/u'],
            'inscripcion' => ['nullable', 'string', 'max:400', 'not_regex:/[<>]/u'],
            'vigente_desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2035-12-31'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', ...$comparaConDesde, 'after_or_equal:'.now('America/Lima')->toDateString(), 'before_or_equal:2035-12-31'],
            'confirmacion_publica' => ['accepted'],
        ];
    }
}
