<?php

namespace App\Http\Requests;

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Validator;

class StoreMatriculaRequest extends FormRequest
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
                Rule::exists('estudiantes', 'id')->where('estado', 'activo')->whereNull('deleted_at'),
            ],
            'curso_id' => [
                'required',
                'integer',
                Rule::exists('cursos', 'id')->where('estado', 'activo')->whereNull('deleted_at'),
            ],
            // Opcional: la convocatoria (por su identificador público) de la que sale el cupo. Sin ella, el cupo es el del curso.
            'convocatoria' => [
                'nullable',
                'uuid',
                Rule::exists('convocatorias', 'identificador')->whereNull('deleted_at'),
            ],
            'fecha_matricula' => ['required', 'date', 'after_or_equal:2020-01-01', 'before_or_equal:'.HoraLocal::hoy()],
            'estado' => ['required', Rule::in(['pendiente', 'anulada'])],
            'monto_pagado' => ['prohibited'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'crear_obligacion' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('estado') === 'anulada') {
                    return;
                }

                $estudianteId = Entrada::entero($this->input('estudiante_id'));
                $cursoId = Entrada::entero($this->input('curso_id'));

                $estudiante = Estudiante::query()->find($estudianteId);
                if ($estudiante !== null && $estudiante->fecha_nacimiento !== null
                    && $estudiante->fecha_nacimiento->toDateString() > StoreEstudianteRequest::FECHA_NACIMIENTO_MAXIMA) {
                    $validator->errors()->add('estudiante_id', 'No se permite matricular a estudiantes nacidos después del año 2010.');

                    return;
                }

                if (Matricula::query()
                    ->where('estudiante_id', $estudianteId)
                    ->where('curso_id', $cursoId)
                    ->where('estado', '!=', 'anulada')
                    ->exists()) {
                    $validator->errors()->add('estudiante_id', 'El estudiante ya está matriculado en este curso.');
                }

                $identificador = $this->input('convocatoria');
                if (is_string($identificador) && $identificador !== '') {
                    // El cupo sale de la convocatoria, no del curso. Se relee bajo bloqueo en el servicio.
                    $convocatoria = Convocatoria::query()->where('identificador', strtolower($identificador))->first();
                    if ($convocatoria === null || (int) $convocatoria->curso_id !== $cursoId) {
                        $validator->errors()->add('convocatoria', 'La convocatoria no pertenece al curso seleccionado.');
                    } elseif (! in_array($convocatoria->estado, Convocatoria::MATRICULABLES, true)) {
                        $validator->errors()->add('convocatoria', 'La convocatoria no admite matrículas en su estado actual.');
                    } elseif ($convocatoria->cupos_disponibles <= 0) {
                        $validator->errors()->add('convocatoria', 'La convocatoria no tiene cupos disponibles.');
                    }

                    return;
                }

                $curso = Curso::query()->find($cursoId);
                if ($curso === null || $curso->cupos_disponibles <= 0) {
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
            'estudiante_id.exists' => 'El estudiante seleccionado no está activo.',
            'curso_id.required' => 'Debes seleccionar un curso.',
            'curso_id.integer' => 'El curso seleccionado no es válido.',
            'curso_id.exists' => 'El curso seleccionado no está disponible.',
            'convocatoria.uuid' => 'La convocatoria seleccionada no es válida.',
            'convocatoria.exists' => 'La convocatoria seleccionada no está disponible.',
            'fecha_matricula.required' => 'La fecha de matrícula es obligatoria.',
            'fecha_matricula.date' => 'La fecha de matrícula no es válida.',
            'estado.required' => 'Debes seleccionar el estado de la matrícula.',
            'estado.in' => 'Las matrículas nuevas solo pueden registrarse como pendientes o anuladas; el estado pagada se deriva de Finanzas.',
            'monto_pagado.prohibited' => 'El monto pagado se calcula automáticamente desde el libro de pagos.',
        ];
    }
}
