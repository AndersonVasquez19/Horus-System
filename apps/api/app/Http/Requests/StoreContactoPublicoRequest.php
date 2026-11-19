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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]{6,30}$/', 'required_without:correo'],
            'correo' => ['nullable', 'string', 'email:rfc', 'max:160', 'required_without:telefono'],
            'asunto' => ['required', 'string', Rule::in(MensajeContacto::ASUNTOS)],
            'mensaje' => ['required', 'string', 'min:5', 'max:1500'],
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
            'telefono.regex' => 'Escribe un teléfono válido.',
            'telefono.required_without' => 'Indica un teléfono o un correo para poder responderte.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.required_without' => 'Indica un teléfono o un correo para poder responderte.',
            'asunto.required' => 'Selecciona un asunto.',
            'asunto.in' => 'Selecciona un asunto de la lista.',
            'mensaje.required' => 'Cuéntanos qué necesitas.',
            'mensaje.min' => 'Escribe un mensaje un poco más largo.',
            'mensaje.max' => 'El mensaje no debe superar los 1500 caracteres.',
            'sitio_web.prohibited' => 'No se pudo enviar el mensaje.',
        ];
    }
}
