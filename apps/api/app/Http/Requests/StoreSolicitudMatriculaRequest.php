<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Email;
use App\Support\FormularioSolicitudMatricula;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StoreSolicitudMatriculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('catalogo-publico.enabled') && Schema::hasTable('solicitudes_matricula'), 503, 'La recepción de solicitudes no está disponible.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        $datos = ['email' => Email::normalize($this->input('email'))];
        if ($this->has('representante_email')) {
            $datos['representante_email'] = Email::normalize($this->input('representante_email'));
        }
        foreach (['nombres', 'apellidos', 'telefono', 'mensaje', 'representante_nombre', 'representante_telefono'] as $campo) {
            if (is_string($this->input($campo))) {
                $datos[$campo] = trim($this->input($campo));
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $menor = fn () => $this->boolean('es_menor');

        return [
            'nombres' => ['required', 'string', 'min:2', 'max:120'],
            'apellidos' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'telefono' => ['required', 'string', 'regex:/\A[+0-9() .-]{7,30}\z/D'],
            'curso' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
            // Participante menor de edad: se registra a la persona adulta responsable (representante).
            'es_menor' => ['sometimes', 'boolean'],
            'representante_nombre' => [Rule::requiredIf($menor), 'nullable', 'string', 'min:2', 'max:120'],
            'representante_email' => [Rule::requiredIf($menor), 'nullable', 'email:rfc', 'max:254'],
            'representante_telefono' => [Rule::requiredIf($menor), 'nullable', 'string', 'regex:/\A[+0-9() .-]{7,30}\z/D'],
            'consentimiento' => ['accepted'],
            'marca' => ['required', 'string', 'max:1024'],
            'sitio_web' => ['nullable', 'string', 'max:0'],
            'estado' => ['prohibited'], 'curso_id' => ['prohibited'], 'estudiante_id' => ['prohibited'],
            'revisado_por' => ['prohibited'], 'identificador' => ['prohibited'], 'huella' => ['prohibited'], 'huella_duplicado' => ['prohibited'], 'nota_revision' => ['prohibited'], 'revision' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (FormularioSolicitudMatricula::leer($this->input('marca')) === null) {
                $validator->errors()->add('marca', 'El formulario caducó o se envió demasiado rápido. Revisa los datos e inténtalo de nuevo.');
            }
        });
    }
}
