<?php

namespace App\Http\Requests;

use App\Models\Curso;
use App\Models\Matricula;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estudiante_id' => [
                'required',
                'integer',
                Rule::exists('estudiantes', 'id')->whereNull('deleted_at'),
            ],
            'curso_id' => [
                'required',
                'integer',
                Rule::exists('cursos', 'id')->whereNull('deleted_at'),
            ],
            'fecha_matricula' => ['required', 'date'],
            'estado' => ['required', Rule::in(['pendiente', 'pagada', 'anulada'])],
            'monto_pagado' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'observaciones' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('estado') === 'anulada') {
                    return;
                }

                $matricula = $this->route('matricula');
                $estudianteId = (int) $this->input('estudiante_id');
                $cursoId = (int) $this->input('curso_id');
                $duplicada = Matricula::query()
                    ->where('estudiante_id', $estudianteId)
                    ->where('curso_id', $cursoId)
                    ->where('estado', '!=', 'anulada')
                    ->where('id', '!=', $matricula->id)
                    ->exists();

                if ($duplicada) {
                    $validator->errors()->add('estudiante_id', 'El estudiante ya está matriculado en este curso.');
                }

                $requiereCupo = $matricula->estado === 'anulada' || $matricula->curso_id !== $cursoId;
                if ($requiereCupo && Curso::query()->findOrFail($cursoId)->cupos_disponibles <= 0) {
                    $validator->errors()->add('curso_id', 'El curso no tiene cupos disponibles.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'estudiante_id.required' => 'Debes seleccionar un estudiante.',
            'estudiante_id.integer' => 'El estudiante seleccionado no es válido.',
            'estudiante_id.exists' => 'El estudiante seleccionado no está disponible.',
            'curso_id.required' => 'Debes seleccionar un curso.',
            'curso_id.integer' => 'El curso seleccionado no es válido.',
            'curso_id.exists' => 'El curso seleccionado no está disponible.',
            'fecha_matricula.required' => 'La fecha de matrícula es obligatoria.',
            'fecha_matricula.date' => 'La fecha de matrícula no es válida.',
            'estado.required' => 'Debes seleccionar el estado de la matrícula.',
            'estado.in' => 'El estado seleccionado no es válido.',
            'monto_pagado.numeric' => 'El monto pagado debe ser un número.',
            'monto_pagado.min' => 'El monto pagado no puede ser negativo.',
            'monto_pagado.max' => 'El monto pagado supera el máximo permitido.',
        ];
    }
}
