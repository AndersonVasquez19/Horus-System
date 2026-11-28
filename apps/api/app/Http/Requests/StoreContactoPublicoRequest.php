<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MensajeContacto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mensaje del formulario «Contáctanos». Lista cerrada de campos; `sitio_web` es una trampa para robots y debe llegar vacío. */
class StoreContactoPublicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mezcla = [];
        foreach (['nombre', 'telefono', 'mensaje'] as $campo) {
            if ($this->has($campo) && is_string($this->input($campo))) {
                $mezcla[$campo] = trim($this->input($campo));
            }
        }
        $this->merge($mezcla);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u', 'regex:/\A[\p{L}\s\'\-.]+\z/u', 'regex:/\p{L}/u'],
            'telefono' => ['nullable', 'string', 'regex:/\A\d{9}\z/D', 'required_without:correo'],
            'correo' => ['nullable', 'string', 'email:rfc', 'max:160', 'required_without:telefono'],
            'asunto' => ['required', 'string', Rule::in(MensajeContacto::ASUNTOS)],
            'mensaje' => ['required', 'string', 'min:5', 'max:1500', 'not_regex:/[<>]/u', 'regex:/\p{L}/u'],
            'sitio_web' => ['nullable', 'prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe tu nombre.',
            'nombre.min' => 'Escribe tu nombre completo.',
            'nombre.not_regex' => 'El nombre contiene caracteres no permitidos.',
            'nombre.regex' => 'Escribe un nombre válido usando solo letras.',
            'telefono.regex' => 'Escribe un teléfono válido de 9 dígitos.',
            'telefono.required_without' => 'Indica un teléfono o un correo para poder responderte.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.required_without' => 'Indica un teléfono o un correo para poder responderte.',
            'asunto.required' => 'Selecciona un asunto.',
            'asunto.in' => 'Selecciona un asunto de la lista.',
            'mensaje.required' => 'Cuéntanos qué necesitas.',
            'mensaje.min' => 'Escribe un mensaje un poco más largo.',
            'mensaje.max' => 'El mensaje no debe superar los 1500 caracteres.',
            'mensaje.not_regex' => 'El mensaje contiene caracteres no permitidos.',
            'mensaje.regex' => 'Escribe un mensaje legible.',
            'sitio_web.prohibited' => 'No se pudo enviar el mensaje.',
        ];
    }
}
