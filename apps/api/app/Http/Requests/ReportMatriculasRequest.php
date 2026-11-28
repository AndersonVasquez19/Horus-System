<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

class ReportMatriculasRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Los permisos por formato se aplican en el middleware del controlador.
        return true;
    }

    /** @return array<string, list<string|In|Exists>> */
    public function rules(): array
    {
        return [
            'tipo' => ['nullable', Rule::in(['matriculas', 'cobranza'])],
            'busqueda' => ['nullable', 'string', 'max:200'],
            'estado' => ['nullable', Rule::in(['pendiente', 'pagada', 'anulada', 'parcial', 'vigente'])],
            'curso_id' => ['nullable', 'integer', Rule::exists('cursos', 'id')],
            'eliminados' => ['nullable', 'boolean'],
            'completo' => ['nullable', 'boolean'],
            'lote' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function tipo(): string
    {
        $tipo = $this->validated('tipo');

        return is_string($tipo) && $tipo !== '' ? $tipo : 'matriculas';
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filtros = array_filter(
            $this->safe()->only(['busqueda', 'curso_id', 'estado', 'eliminados']),
            fn ($value) => $value !== null && $value !== '',
        );

        $tipo = $this->validated('tipo');
        if (is_string($tipo) && $tipo !== '' && $tipo !== 'matriculas') {
            $filtros['tipo'] = $tipo;
        }

        return $filtros;
    }

    public function messages(): array
    {
        return [
            'tipo.in' => 'Selecciona un tipo de reporte válido.',
            'busqueda.string' => 'La búsqueda debe ser un texto.',
            'busqueda.max' => 'La búsqueda no debe superar los 200 caracteres.',
            'estado.in' => 'Selecciona un estado válido.',
            'curso_id.integer' => 'Selecciona un curso válido.',
            'curso_id.exists' => 'El curso seleccionado no existe.',
            'eliminados.boolean' => 'La selección de matrículas eliminadas no es válida.',
            'completo.boolean' => 'La opción de reporte completo no es válida.',
            'lote.integer' => 'El lote debe ser un número entero.',
            'lote.min' => 'El lote debe ser mayor o igual a 1.',
            'lote.max' => 'El lote solicitado está fuera del rango permitido.',
        ];
    }
}
