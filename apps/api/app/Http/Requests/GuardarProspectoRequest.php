<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Prospecto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos de un prospecto. La revisión (`_revision`) la traslada `PrecondicionesPanel`. `estado`, `revision`,
 * `identificador`, `responsable_id`, `cliente_id` y `motivo_descarte` no se aceptan: los fijan las operaciones del servicio.
 * El origen `cotizacion` es reservado (lo fija la conversión de una solicitud).
 */
class GuardarProspectoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'prospectos.crear' : 'prospectos.editar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $limpiar = static fn (mixed $valor): mixed => is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
        $datos = [];
        foreach (['nombre_contacto', 'empresa', 'email', 'telefono', 'origen', 'servicio_interes', 'proximo_seguimiento'] as $campo) {
            if ($this->has($campo)) {
                $datos[$campo] = $limpiar($this->input($campo));
            }
        }
        foreach (['email', 'origen', 'servicio_interes'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = strtolower($datos[$campo]);
            }
        }
        $this->merge($datos);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre_contacto' => ['required', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'empresa' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/u'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/\A[0-9+() -]{6,30}\z/D'],
            'origen' => ['required', 'string', Rule::in(Prospecto::ORIGENES_MANUALES)],
            'servicio_interes' => ['nullable', 'uuid'],
            'proximo_seguimiento' => ['nullable', 'date_format:Y-m-d'],
            'estado' => ['missing'],
            'revision' => ['missing'],
            'identificador' => ['missing'],
            'responsable_id' => ['missing'],
            'cliente_id' => ['missing'],
            'motivo_descarte' => ['missing'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validador): void {
            $datos = $validador->getData();
            if (blank($datos['email'] ?? null) && blank($datos['telefono'] ?? null)) {
                $validador->errors()->add('email', 'Indica al menos correo o teléfono de contacto.');
            }
        }];
    }
}
