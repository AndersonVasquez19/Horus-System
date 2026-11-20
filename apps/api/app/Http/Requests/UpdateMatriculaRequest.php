<?php

namespace App\Http\Requests;

use App\Models\Matricula;
use App\Support\Entrada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Validator;

class UpdateMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Exists|In>> */
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
            // La convocatoria queda fija desde el alta: no se cambia ni se quita al editar.
            'convocatoria' => ['prohibited'],
            'fecha_matricula' => ['required', 'date'],
            'estado' => ['required', Rule::in(['pendiente', 'pagada', 'anulada'])],
            'monto_pagado' => ['prohibited'],
            'motivo' => ['nullable', 'string', 'min:3', 'max:200'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $matricula = $this->route('matricula');
                if (! $matricula instanceof Matricula) {
                    return;
                }
                $estadoSolicitado = Entrada::texto($this->input('estado'));
                if ($estadoSolicitado === 'pagada' && $matricula->estado !== 'pagada') {
                    $validator->errors()->add('estado', 'El estado pagada se asigna automáticamente desde el libro de pagos.');

                    return;
                }
                if ($matricula->estado === 'pagada' && $estadoSolicitado === 'pendiente') {
                    $validator->errors()->add('estado', 'Una matrícula pagada solo vuelve a pendiente al reversar su pago en Finanzas.');

                    return;
                }
                if ($estadoSolicitado === 'anulada') {
                    if ($matricula->estado !== 'anulada') {
                        $motivo = $this->input('motivo');
                        if (! is_string($motivo) || mb_strlen(trim($motivo)) < 3 || mb_strlen(trim($motivo)) > 200) {
                            $validator->errors()->add('motivo', 'Debes indicar un motivo de entre 3 y 200 caracteres para anular la matrícula.');
                        }
                    }

                    return;
                }

                $estudianteId = Entrada::entero($this->input('estudiante_id'));
                $cursoId = Entrada::entero($this->input('curso_id'));
                $duplicada = Matricula::query()
                    ->where('estudiante_id', $estudianteId)
                    ->where('curso_id', $cursoId)
                    ->where('estado', '!=', 'anulada')
                    ->where('id', '!=', $matricula->id)
                    ->exists();

                if ($duplicada) {
                    $validator->errors()->add('estudiante_id', 'El estudiante ya está matriculado en este curso.');
                }

                // La necesidad de un cupo depende de la fila actual, no del route binding.
                // El controlador la comprueba junto con la disponibilidad bajo lock.
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
            'convocatoria.prohibited' => 'La convocatoria de una matrícula no se puede cambiar.',
            'fecha_matricula.required' => 'La fecha de matrícula es obligatoria.',
            'fecha_matricula.date' => 'La fecha de matrícula no es válida.',
            'estado.required' => 'Debes seleccionar el estado de la matrícula.',
            'estado.in' => 'El estado seleccionado no es válido.',
            'monto_pagado.prohibited' => 'El monto pagado se calcula automáticamente desde el libro de pagos.',
            'motivo.min' => 'El motivo debe tener al menos 3 caracteres.',
            'motivo.max' => 'El motivo no puede superar los 200 caracteres.',
        ];
    }
}
