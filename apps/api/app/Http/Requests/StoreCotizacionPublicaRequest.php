<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\RecepcionCotizacionesPublicas;
use App\Support\Email;
use App\Support\FormularioCotizacionPublica;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud pública de cotización recibida por el BFF de apps/web (Fase 2c.3). Los servicios llegan como referencias
 * (`identificador_publico`) que el servicio de registro vuelve a resolver; precio, estado, responsable, código y origen
 * son campos del servidor y se rechazan si el cliente los envía. Apagada la recepción, responde 503 antes de validar.
 */
class StoreCotizacionPublicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RecepcionCotizacionesPublicas::class)->habilitado(), 503, 'La recepción de solicitudes no está disponible.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        $datos = [];
        if ($this->has('email')) {
            $datos['email'] = Email::normalize($this->input('email'));
        }
        foreach (['nombre', 'empresa', 'telefono', 'ubicacion', 'necesidad', 'informacion'] as $campo) {
            if (is_string($this->input($campo))) {
                $datos[$campo] = trim($this->input($campo));
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'servicios' => ['required', 'array', 'min:1', 'max:'.RecepcionCotizacionesPublicas::MAX_SERVICIOS],
            'servicios.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'nombre' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u'],
            'empresa' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'telefono' => ['nullable', 'string', 'regex:/\A[+0-9() .-]{5,30}\z/D'],
            'ubicacion' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/u'],
            'necesidad' => ['required', 'string', 'min:10', 'max:3000', 'not_regex:/[<>]/u'],
            'informacion' => ['nullable', 'string', 'max:1000', 'not_regex:/[<>]/u'],
            'consentimiento' => ['accepted'],
            'marca' => ['required', 'string', 'max:1024'],
            'sitio_web' => ['nullable', 'string', 'max:0'],
            // Campos del servidor: nunca se aceptan del navegador (tampoco precios ni montos).
            'estado' => ['prohibited'], 'codigo' => ['prohibited'], 'identificador' => ['prohibited'], 'origen' => ['prohibited'],
            'responsable_id' => ['prohibited'], 'servicio_id' => ['prohibited'], 'servicio_uuid' => ['prohibited'], 'revision' => ['prohibited'],
            'precio' => ['prohibited'], 'monto' => ['prohibited'], 'total' => ['prohibited'], 'creado_por' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'servicios.required' => 'Elige al menos un servicio.',
            'servicios.min' => 'Elige al menos un servicio.',
            'servicios.max' => 'Puedes elegir hasta '.RecepcionCotizacionesPublicas::MAX_SERVICIOS.' servicios.',
            'consentimiento.accepted' => 'Debes aceptar para que podamos atender tu solicitud.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (FormularioCotizacionPublica::leer($this->input('marca')) === null) {
                $validator->errors()->add('marca', 'El formulario caducó o se envió demasiado rápido. Revisa los datos e inténtalo de nuevo.');
            }
        });
    }
}
