<?php

namespace App\Http\Requests;

use App\Models\Curso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Unique|In>> */
    public function rules(): array
    {
        $curso = $this->route('curso');
        $id = $curso instanceof Curso ? $curso->id : null;

        return [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('cursos', 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['required', 'string'],
            'categoria' => ['required', Rule::in(['tecnologia', 'educacion', 'salud', 'gestion', 'otros'])],
            'duracion_horas' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'precio' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'modalidad' => ['required', Rule::in(['presencial', 'virtual', 'hibrido'])],
            'horario' => ['required', 'string', 'max:100'],
            'cupos_maximos' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'estado' => ['required', Rule::in(['activo', 'inactivo', 'archivado'])],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('cupos_maximos')) {
                    return;
                }

                $curso = $this->route('curso');
                $matriculasActivas = $curso->matriculas()
                    ->where('estado', '!=', 'anulada')
                    ->count();

                if ((int) $this->input('cupos_maximos') < $matriculasActivas) {
                    $validator->errors()->add(
                        'cupos_maximos',
                        "No puedes reducir los cupos por debajo de las {$matriculasActivas} matrículas activas.",
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El código del curso es obligatorio.',
            'codigo.max' => 'El código no debe superar los 20 caracteres.',
            'codigo.unique' => 'Ya existe un curso con este código.',
            'nombre.required' => 'El nombre del curso es obligatorio.',
            'nombre.max' => 'El nombre no debe superar los 150 caracteres.',
            'descripcion.required' => 'La descripción del curso es obligatoria.',
            'categoria.required' => 'Debes seleccionar una categoría.',
            'categoria.in' => 'La categoría seleccionada no es válida.',
            'duracion_horas.required' => 'La duración del curso es obligatoria.',
            'duracion_horas.integer' => 'La duración debe indicarse en horas enteras.',
            'duracion_horas.min' => 'La duración debe ser de al menos una hora.',
            'precio.required' => 'El precio es obligatorio.',
            'precio.numeric' => 'El precio debe ser un número.',
            'precio.min' => 'El precio debe ser mayor o igual a 0.',
            'modalidad.required' => 'Debes seleccionar una modalidad.',
            'modalidad.in' => 'La modalidad seleccionada no es válida.',
            'horario.required' => 'El horario del curso es obligatorio.',
            'horario.max' => 'El horario no debe superar los 100 caracteres.',
            'cupos_maximos.required' => 'Debes indicar la cantidad de cupos.',
            'cupos_maximos.integer' => 'Los cupos deben ser un número entero.',
            'cupos_maximos.min' => 'Los cupos deben ser al menos 1.',
            'estado.required' => 'Debes seleccionar el estado del curso.',
            'estado.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
