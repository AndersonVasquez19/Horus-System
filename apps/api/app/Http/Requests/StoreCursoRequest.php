<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

class StoreCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Unique|In>> */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('cursos', 'codigo')],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['required', 'string', 'max:5000'],
            'categoria' => ['required', Rule::in(['tecnologia', 'educacion', 'salud', 'gestion', 'otros'])],
            'duracion_horas' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'precio' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'modalidad' => ['required', Rule::in(['presencial', 'semipresencial', 'virtual', 'hibrido'])],
            'horario' => ['required', 'string', 'max:100'],
            'enlace_grupo_whatsapp' => ['nullable', 'string', 'max:255', 'regex:/\Ahttps:\/\/chat\.whatsapp\.com\/[A-Za-z0-9]{10,120}\z/'],
            'cupos_maximos' => ['required', 'integer', 'min:1', 'max:'.(int) config('academico.cupos_maximos', 10)],
            'estado' => ['required', Rule::in(['activo', 'inactivo', 'archivado'])],
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
            'enlace_grupo_whatsapp.max' => 'El enlace del grupo no debe superar los 255 caracteres.',
            'enlace_grupo_whatsapp.regex' => 'El enlace debe pertenecer a un grupo válido de WhatsApp (https://chat.whatsapp.com/…).',
            'cupos_maximos.required' => 'Debes indicar la cantidad de cupos.',
            'cupos_maximos.integer' => 'Los cupos deben ser un número entero.',
            'cupos_maximos.min' => 'Los cupos deben ser al menos 1.',
            'cupos_maximos.max' => 'Un curso admite como máximo '.(int) config('academico.cupos_maximos', 10).' cupos.',
            'estado.required' => 'Debes seleccionar el estado del curso.',
            'estado.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
