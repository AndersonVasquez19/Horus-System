<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicarCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cursos.publicar') ?? false;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120'],
            'resumen' => ['required', 'string', 'max:500'],
            'modalidad' => ['required', Rule::in(['presencial', 'semipresencial', 'virtual', 'hibrido'])],
            'horario' => ['nullable', 'string', 'max:200'],
            'inscripcion' => ['nullable', 'string', 'max:400'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:vigente_desde', 'after_or_equal:'.now('America/Lima')->toDateString()],
            'confirmacion_publica' => ['accepted'],
        ];
    }
}
